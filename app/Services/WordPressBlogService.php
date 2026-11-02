<?php
declare(strict_types=1);

namespace App\Services;

final class WordPressBlogService
{
    /** @param array<string,mixed> $settings @return array<string,mixed> */
    public function testConnection(array $settings, string $applicationPassword): array
    {
        $siteUrl = $this->siteUrl((string)($settings['site_url'] ?? ''));
        $username = trim((string)($settings['wp_username'] ?? ''));
        if ($username === '' || $applicationPassword === '') throw new \RuntimeException('Save the WordPress username and Application Password first.');
        return $this->request('GET', $siteUrl . '/wp-json/example-studio/v1/status', $username, $applicationPassword);
    }

    /** @return array<int,array{title:string,url:string,type:string}> */
    public function linkInventory(string $siteUrl): array
    {
        $siteUrl = $this->siteUrl($siteUrl);
        $inventory = [];
        try {
            $rows = $this->request('GET', $siteUrl . '/wp-json/wp/v2/search?per_page=100&type=post&_fields=title,url,subtype', '', '');
            foreach ($rows as $row) {
                $title = html_entity_decode(strip_tags((string)($row['title'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $url = (string)($row['url'] ?? '');
                if ($title !== '' && $this->isPublicHttpsUrl($url)) {
                    $inventory[] = ['title' => $title, 'url' => $url, 'type' => (string)($row['subtype'] ?? 'post')];
                }
            }
        } catch (\Throwable) {
            // Fall back to the standard posts/pages endpoints below.
        }
        if ($inventory !== []) return array_slice($inventory, 0, 100);
        foreach (['posts', 'pages'] as $type) {
            try {
                $rows = $this->request('GET', $siteUrl . '/wp-json/wp/v2/' . $type . '?per_page=40&status=publish&_fields=link,title', '', '');
            } catch (\Throwable) {
                continue;
            }
            if (!is_array($rows)) continue;
            foreach ($rows as $row) {
                $title = html_entity_decode(strip_tags((string)($row['title']['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $url = (string)($row['link'] ?? '');
                if ($title !== '' && $this->isPublicHttpsUrl($url)) {
                    $inventory[] = ['title' => $title, 'url' => $url, 'type' => rtrim($type, 's')];
                }
            }
        }
        return array_slice($inventory, 0, 60);
    }

    /** @return array<int,array{id:int,title:string,url:string,alt:string}> */
    public function mediaInventory(string $siteUrl): array
    {
        $siteUrl = $this->siteUrl($siteUrl);
        try {
            $rows = $this->request('GET', $siteUrl . '/wp-json/wp/v2/media?per_page=60&media_type=image&status=inherit&_fields=id,source_url,alt_text,title', '', '');
        } catch (\Throwable) {
            return [];
        }
        $media = [];
        foreach ($rows as $row) {
            $url = (string)($row['source_url'] ?? '');
            if (!$this->isPublicHttpsUrl($url)) continue;
            $media[] = [
                'id' => (int)($row['id'] ?? 0),
                'title' => html_entity_decode(strip_tags((string)($row['title']['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'url' => $url,
                'alt' => trim((string)($row['alt_text'] ?? '')),
            ];
        }
        return array_values(array_filter($media, static fn(array $item): bool => $item['id'] > 0));
    }

    /** @param array<string,mixed> $article @return array<string,mixed> */
    public function createArticle(array $settings, string $applicationPassword, array $article): array
    {
        $siteUrl = $this->siteUrl((string)($settings['site_url'] ?? ''));
        $username = trim((string)($settings['wp_username'] ?? ''));
        if ($username === '' || $applicationPassword === '') {
            throw new \RuntimeException('WordPress username and Application Password are required.');
        }

        $payload = [
            'title' => (string)$article['title'],
            'slug' => (string)$article['slug'],
            'content_html' => (string)$article['content_html'],
            'excerpt' => (string)$article['excerpt'],
            'status' => ($settings['publish_mode'] ?? 'draft') === 'publish' ? 'publish' : 'draft',
            'focus_keyphrase' => (string)$article['focus_keyphrase'],
            'seo_title' => (string)$article['seo_title'],
            'meta_description' => (string)$article['meta_description'],
            'image_alt' => (string)($article['image_alt'] ?? ''),
            'tags' => array_values(array_slice(array_filter((array)($article['tags'] ?? []), 'is_string'), 0, 8)),
            'sources' => array_values((array)($article['sources'] ?? [])),
            'category_id' => max(0, (int)($settings['category_id'] ?? 0)),
            'featured_media_id' => max(0, (int)($article['featured_media_id'] ?? $settings['featured_media_id'] ?? 0)),
        ];

        $result = $this->request(
            'POST',
            $siteUrl . '/wp-json/example-studio/v1/articles',
            $username,
            $applicationPassword,
            $payload
        );
        if (empty($result['id'])) {
            throw new \RuntimeException('WordPress connector returned an invalid response.');
        }
        return $result;
    }

    private function siteUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if (!$this->isPublicHttpsUrl($url)) {
            throw new \RuntimeException('WordPress Site URL must be a public HTTPS URL.');
        }
        return $url;
    }

    private function isPublicHttpsUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($url), 'https://')) return false;
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) return false;
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }
        return true;
    }

    /** @param array<string,mixed>|null $payload @return array<mixed> */
    private function request(string $method, string $url, string $username, string $password, ?array $payload = null): array
    {
        $headers = ['Accept: application/json'];
        if ($payload !== null) $headers[] = 'Content-Type: application/json';
        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($username !== '') $options[CURLOPT_USERPWD] = $username . ':' . $password;
        if ($payload !== null) $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException('WordPress request failed (HTTP ' . $status . '): ' . ($error ?: substr((string)$raw, 0, 700)));
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) throw new \RuntimeException('WordPress returned invalid JSON.');
        return $decoded;
    }
}
