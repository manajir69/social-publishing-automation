<?php
declare(strict_types=1);

namespace App\Services;

use App\Security\Crypto;
use PDO;

final class BlogAutomationService
{
    public function __construct(private PDO $db) {}

    /** @return array<int,array{site_id:int,site_name:string,result:string,message:string,article_id?:int}> */
    public function runDueSites(): array
    {
        $sites = $this->db->query("SELECT id,site_name FROM blog_settings WHERE enabled=1 ORDER BY id")->fetchAll();
        $results = [];
        foreach ($sites as $site) {
            try {
                $result = $this->runSite((int)$site['id'], false);
            } catch (\Throwable $e) {
                $result = ['result' => 'failed', 'message' => substr($e->getMessage(), 0, 1500)];
            }
            $results[] = ['site_id' => (int)$site['id'], 'site_name' => (string)$site['site_name']] + $result;
        }
        return $results;
    }

    /** @return array{result:string,message:string,article_id?:int} */
    public function runSite(int $siteId, bool $manual = false): array
    {
        if ($siteId < 1) throw new \RuntimeException('Select a WordPress site first.');
        $lockName = 'social_blog_automation_' . $siteId;
        $lock = $this->db->prepare('SELECT GET_LOCK(?,1)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            return ['result' => 'skipped', 'message' => 'Another blog run is already active.'];
        }
        $articleId = null;
        try {
            $settingsQuery = $this->db->prepare('SELECT * FROM blog_settings WHERE id=?');
            $settingsQuery->execute([$siteId]);
            $settings = $settingsQuery->fetch();
            if (!$settings) throw new \RuntimeException('WordPress site not found.');
            if (!$manual && !(bool)$settings['enabled']) return ['result' => 'skipped', 'message' => 'Blog automation is disabled.'];
            if (!$manual && !$this->isDue($settings)) return ['result' => 'skipped', 'message' => 'The next blog article is not due yet.'];

            if (!$manual) {
                $claim = $this->db->prepare('UPDATE blog_settings SET last_run_on=? WHERE id=? AND (last_run_on IS NULL OR last_run_on<>?)');
                $today = date('Y-m-d');
                $claim->execute([$today, $siteId, $today]);
                if ($claim->rowCount() !== 1) return ['result' => 'skipped', 'message' => 'Blog automation already ran today.'];
            }

            $failedQuery = $this->db->prepare("SELECT * FROM blog_articles WHERE blog_site_id=? AND status='failed' AND wp_post_id IS NULL ORDER BY id ASC LIMIT 1");
            $failedQuery->execute([$siteId]);
            $failed = $failedQuery->fetch();
            if ($failed) return $this->retryWordPressDelivery($settings, $failed);

            $apiKey = Crypto::decrypt((string)($settings['encrypted_ai_api_key'] ?? ''));
            if ($apiKey === '') {
                $fallback = $this->db->query("SELECT encrypted_ai_api_key FROM social_pages WHERE status='active' AND ai_provider='openai' AND encrypted_ai_api_key IS NOT NULL AND encrypted_ai_api_key<>'' ORDER BY id LIMIT 1")->fetchColumn();
                $apiKey = Crypto::decrypt((string)$fallback);
            }
            $appPassword = Crypto::decrypt((string)($settings['encrypted_wp_app_password'] ?? ''));
            $recentQuery = $this->db->prepare('SELECT topic,focus_keyphrase FROM blog_articles WHERE blog_site_id=? ORDER BY id DESC LIMIT 50');
            $recentQuery->execute([$siteId]);
            $recentRows = $recentQuery->fetchAll();
            $recent = array_map(static fn(array $row): string => $row['topic'] . ' | ' . $row['focus_keyphrase'], $recentRows);

            $ai = new BlogAIService($apiKey, (string)($settings['ai_model'] ?: 'gpt-5-mini'));
            $research = $ai->research((string)$settings['topics'], $recent, (string)($settings['system_prompt'] ?? ''), (string)$settings['site_name']);
            $wordpress = new WordPressBlogService();
            $links = $wordpress->linkInventory((string)$settings['site_url']);
            $media = $wordpress->mediaInventory((string)$settings['site_url']);
            $article = $ai->write($research, $links, $media, (string)($settings['system_prompt'] ?? ''), (string)$settings['site_name']);
            if ((int)($article['featured_media_id'] ?? 0) === 0 && (int)($settings['featured_media_id'] ?? 0) > 0) {
                foreach ($media as $mediaItem) {
                    if ((int)$mediaItem['id'] !== (int)$settings['featured_media_id']) continue;
                    $article['featured_media_id'] = (int)$mediaItem['id'];
                    if (stripos((string)$article['content_html'], '<img') === false) {
                        $src = htmlspecialchars((string)$mediaItem['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $alt = htmlspecialchars((string)$article['image_alt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $article['content_html'] = '<figure><img src="' . $src . '" alt="' . $alt . '"></figure>' . $article['content_html'];
                    }
                    break;
                }
            }
            [$seoScore, $seoChecks, $internalLinks] = $this->seoReview($article, (string)$settings['site_url']);

            $insert = $this->db->prepare('INSERT INTO blog_articles(blog_site_id,topic,focus_keyphrase,title,slug,excerpt,content_html,seo_title,meta_description,image_alt,featured_media_id,sources_json,internal_links_json,tags_json,seo_score,seo_checks_json,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"draft")');
            try {
                $insert->execute([
                    $siteId,
                    $article['topic'], $article['focus_keyphrase'], $article['title'], $article['slug'], $article['excerpt'], $article['content_html'],
                    $article['seo_title'], $article['meta_description'], $article['image_alt'], $article['featured_media_id'] ?: null,
                    json_encode($article['sources'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    json_encode($internalLinks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    json_encode($article['tags'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    $seoScore, json_encode($seoChecks, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            } catch (\PDOException $e) {
                if ((string)$e->getCode() === '23000') throw new \RuntimeException('AI selected a previously used focus keyphrase. Run again for a new topic.');
                throw $e;
            }
            $articleId = (int)$this->db->lastInsertId();

            try {
                $wp = $wordpress->createArticle($settings, $appPassword, $article);
                $wpStatus = (string)($wp['status'] ?? 'draft');
                $localStatus = $wpStatus === 'publish' ? 'published' : 'draft';
                $update = $this->db->prepare('UPDATE blog_articles SET wp_post_id=?,wp_post_url=?,wp_status=?,status=?,published_at=?,error_message=NULL WHERE id=?');
                $update->execute([(int)$wp['id'], (string)($wp['link'] ?? ''), $wpStatus, $localStatus, $localStatus === 'published' ? date('Y-m-d H:i:s') : null, $articleId]);
                $this->db->prepare('UPDATE blog_settings SET last_success_at=?,last_run_on=? WHERE id=?')->execute([date('Y-m-d H:i:s'), date('Y-m-d'), $siteId]);
                $message = 'WordPress ' . $wpStatus . ' created: ' . $article['title'];
                $this->log($siteId, 'success', $message, $articleId);
                return ['result' => 'success', 'message' => $message, 'article_id' => $articleId];
            } catch (\Throwable $e) {
                $message = substr($e->getMessage(), 0, 1500);
                $this->db->prepare('UPDATE blog_articles SET status="failed",error_message=? WHERE id=?')->execute([$message, $articleId]);
                throw $e;
            }
        } catch (\Throwable $e) {
            $message = substr($e->getMessage(), 0, 1500);
            $this->log($siteId, 'failed', $message, $articleId);
            throw $e;
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    /** @param array<string,mixed> $settings */
    private function isDue(array $settings): bool
    {
        if (date('H:i') < substr((string)$settings['post_time'], 0, 5)) return false;
        if ((string)($settings['last_run_on'] ?? '') === date('Y-m-d')) return false;
        if (empty($settings['last_success_at'])) return true;
        $lastDate = substr((string)$settings['last_success_at'], 0, 10);
        $last = \DateTimeImmutable::createFromFormat('!Y-m-d', $lastDate);
        if (!$last) return true;
        $dueDate = $last->modify('+' . max(1, (int)$settings['frequency_days']) . ' days')->format('Y-m-d');
        return date('Y-m-d') >= $dueDate;
    }

    /** @param array<string,mixed> $settings @param array<string,mixed> $failed @return array{result:string,message:string,article_id:int} */
    private function retryWordPressDelivery(array $settings, array $failed): array
    {
        $article = $failed;
        $article['sources'] = json_decode((string)($failed['sources_json'] ?? ''), true) ?: [];
        $article['tags'] = json_decode((string)($failed['tags_json'] ?? ''), true) ?: [];
        $password = Crypto::decrypt((string)($settings['encrypted_wp_app_password'] ?? ''));
        $wp = (new WordPressBlogService())->createArticle($settings, $password, $article);
        $wpStatus = (string)($wp['status'] ?? 'draft');
        $localStatus = $wpStatus === 'publish' ? 'published' : 'draft';
        $this->db->prepare('UPDATE blog_articles SET wp_post_id=?,wp_post_url=?,wp_status=?,status=?,published_at=?,error_message=NULL WHERE id=?')->execute([
            (int)$wp['id'], (string)($wp['link'] ?? ''), $wpStatus, $localStatus,
            $localStatus === 'published' ? date('Y-m-d H:i:s') : null, (int)$failed['id'],
        ]);
        $siteId = (int)$settings['id'];
        $this->db->prepare('UPDATE blog_settings SET last_success_at=?,last_run_on=? WHERE id=?')->execute([date('Y-m-d H:i:s'), date('Y-m-d'), $siteId]);
        $message = 'Retried WordPress delivery successfully: ' . $failed['title'];
        $this->log($siteId, 'success', $message, (int)$failed['id']);
        return ['result' => 'success', 'message' => $message, 'article_id' => (int)$failed['id']];
    }

    /** @param array<string,mixed> $article @return array{0:int,1:array<string,bool>,2:string[]} */
    private function seoReview(array $article, string $siteUrl): array
    {
        $key = trim((string)$article['focus_keyphrase']);
        $html = (string)$article['content_html'];
        $plain = trim(strip_tags($html));
        $intro = function_exists('mb_substr') ? mb_substr($plain, 0, 700, 'UTF-8') : substr($plain, 0, 700);
        preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\']/i', $html, $matches);
        $links = array_values(array_unique($matches[1] ?? []));
        $host = strtolower((string)parse_url($siteUrl, PHP_URL_HOST));
        $internal = array_values(array_filter($links, static fn(string $url): bool => strtolower((string)parse_url($url, PHP_URL_HOST)) === $host));
        $external = array_values(array_filter($links, static fn(string $url): bool => ($linkHost = strtolower((string)parse_url($url, PHP_URL_HOST))) !== '' && $linkHost !== $host));
        $checks = [
            'Keyphrase in title' => stripos((string)$article['title'], $key) !== false,
            'Concise SEO title' => $this->textLength((string)$article['seo_title']) >= 35 && $this->textLength((string)$article['seo_title']) <= 65,
            'Keyphrase in slug' => $this->slugContains((string)$article['slug'], $key),
            'Keyphrase in meta description' => stripos((string)$article['meta_description'], $key) !== false,
            'Meta description length' => $this->textLength((string)$article['meta_description']) >= 120 && $this->textLength((string)$article['meta_description']) <= 156,
            'Keyphrase in introduction' => function_exists('mb_stripos') ? mb_stripos($intro, $key, 0, 'UTF-8') !== false : stripos($intro, $key) !== false,
            'Keyphrase in an H2' => preg_match('/<h2[^>]*>[^<]*' . preg_quote($key, '/') . '/i', $html) === 1,
            'No duplicate H1 in body' => preg_match('/<h1\b/i', $html) !== 1,
            'Useful H2 sections' => preg_match_all('/<h2\b/i', $html) >= 3,
            'Internal links' => count($internal) >= 2,
            'Research source links' => count($external) >= 2,
            'Bangla summary' => str_contains($html, 'বাংলায় সংক্ষেপে') && preg_match('/[\x{0980}-\x{09FF}]/u', $html) === 1,
            'Relevant WordPress image' => (int)($article['featured_media_id'] ?? 0) > 0,
            'Image alt text' => stripos((string)($article['image_alt'] ?? ''), $key) !== false && stripos($html, '<img') !== false,
            'Substantial article' => str_word_count($plain) >= 700,
        ];
        $score = (int)round((count(array_filter($checks)) / count($checks)) * 100);
        return [$score, $checks, $internal];
    }

    private function slugContains(string $slug, string $keyphrase): bool
    {
        $needle = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $keyphrase) ?? '', '-'));
        return $needle !== '' && str_contains(strtolower($slug), $needle);
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function log(int $siteId, string $result, string $message, ?int $articleId): void
    {
        try {
            $query = $this->db->prepare('INSERT INTO blog_runs(blog_site_id,result,message,article_id) VALUES(?,?,?,?)');
            $query->execute([$siteId, $result, $message, $articleId]);
        } catch (\Throwable) {
            // The original exception is more useful than a secondary logging failure.
        }
    }
}
