<?php
declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

final class FolderContentService
{
    public const ROOT = BASE_PATH . '/page-content';

    /**
     * Use one image batch from each folder in natural-name order, then start
     * the next round with the next unused batch from the first folder.
     */
    public function prepareNext(array $page, bool $storyOnly = false, string $postType = 'feed', bool $allowImageReset = true, ?string $selectedFolder = null): array
    {
        $slug = self::normalizeName((string)($page['folder_source_slug'] ?? ''));
        $selectedFolder=$selectedFolder!==null?self::normalizeName($selectedFolder):null;
        $pageRoot = self::ROOT . '/' . $slug;
        $realPageRoot = realpath($pageRoot);
        $realRoot = realpath(self::ROOT);
        if ($realRoot === false || $realPageRoot === false || !is_dir($realPageRoot) || !str_starts_with($realPageRoot, $realRoot . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Folder source was not found: page-content/' . $slug);
        }

        $db = Database::connection();
        $pendingSql="SELECT id,title,category,folder_batch_images_json FROM content_items WHERE page_id=? AND source_type='server_folder' AND folder_batch_images_json IS NOT NULL AND last_drafted_at IS NULL AND NOT EXISTS(SELECT 1 FROM social_posts WHERE content_item_id=content_items.id)".($selectedFolder!==null?' AND category=?':'')." ORDER BY id ASC";
        $pending = $db->prepare($pendingSql);
        $pending->execute($selectedFolder!==null?[(int)$page['id'],$selectedFolder]:[(int)$page['id']]);
        foreach ($pending->fetchAll() as $pendingItem) {
            $pendingImages = json_decode((string)$pendingItem['folder_batch_images_json'], true);
            return ['item_id'=>(int)$pendingItem['id'],'title'=>(string)$pendingItem['title'],'folder'=>(string)$pendingItem['category'],'image_count'=>is_array($pendingImages)?count($pendingImages):0];
        }

        $existingQuery = $db->prepare("SELECT * FROM content_items WHERE page_id=? AND source_type='server_folder'");
        $existingQuery->execute([(int)$page['id']]);
        $existing = [];
        foreach ($existingQuery->fetchAll() as $item) $existing[(string)$item['external_id']] = $item;

        // Repair rotation counts left uneven by the older exhausted-folder
        // reset. In a true round-robin, folder counts can differ by at most
        // one. A larger gap would make one folder repeat until it catches up.
        $rotationCounts=array_map(static fn(array $item):int=>(int)($item['folder_rotation_count']??0),array_values($existing));
        if($rotationCounts!==[]&&max($rotationCounts)-min($rotationCounts)>1){
            $baseline=min($rotationCounts);
            $repair=$db->prepare("UPDATE content_items SET folder_rotation_count=? WHERE page_id=? AND source_type='server_folder'");
            $repair->execute([$baseline,(int)$page['id']]);
            foreach($existing as &$existingItem)$existingItem['folder_rotation_count']=$baseline;
            unset($existingItem);
        }

        $folders = array_values(array_filter(glob($realPageRoot . '/*') ?: [], 'is_dir'));
        if($selectedFolder!==null)$folders=array_values(array_filter($folders,static fn(string $folder):bool=>basename($folder)===$selectedFolder));
        if($selectedFolder!==null&&$folders===[])throw new \RuntimeException('Selected server folder was not found: '.$selectedFolder);
        usort($folders, static fn(string $a, string $b): int => strnatcasecmp(basename($a), basename($b)));
        $newFolders = [];
        $repeatFolders = [];
        foreach ($folders as $folder) {
            $relativeFolder = $slug . '/' . basename($folder);
            $externalId = hash('sha256', $relativeFolder);
            $item = $existing[$externalId] ?? null;
            if (!$item) {
                $newFolders[] = [$folder, $relativeFolder, $externalId, null];
                continue;
            }
            $repeatFolders[] = [$folder, $relativeFolder, $externalId, $item];
        }
        // A deterministic order makes each round easy to verify: every new
        // folder gets one turn, then the least-used tracked folder gets the
        // next turn. Equal rotation counts follow the visible folder order.
        usort($newFolders, static fn(array $a, array $b): int => strnatcasecmp(basename($a[0]), basename($b[0])));
        usort($repeatFolders, static function(array $a, array $b): int {
            $rotation = (int)($a[3]['folder_rotation_count'] ?? 0) <=> (int)($b[3]['folder_rotation_count'] ?? 0);
            return $rotation !== 0 ? $rotation : strnatcasecmp(basename($a[0]), basename($b[0]));
        });

        $problems = [];
        foreach ([...$newFolders, ...$repeatFolders] as $candidate) {
            [$folder, $relativeFolder, $externalId, $item] = $candidate;
            try {
                $source = $this->readFolder($realPageRoot, $folder, $relativeFolder, $storyOnly);
            } catch (\RuntimeException $e) {
                $problems[] = basename($folder) . ': ' . $e->getMessage();
                continue;
            }

            $usedImages = $item ? json_decode((string)($item['folder_used_images_json'] ?? ''), true) : [];
            if (!is_array($usedImages)) $usedImages = [];
            $usedImages = array_values(array_intersect(array_map('strval', $usedImages), $source['images']));
            $usedLookup = array_fill_keys(array_map('strval', $usedImages), true);
            $available = array_values(array_filter($source['images'], static fn(string $image): bool => !isset($usedLookup[$image])));
            if ($available === [] && $item && $allowImageReset) {
                // Every folder keeps its place in the round-robin. When this
                // folder has no fresh filename left, start only this folder's
                // next image cycle instead of skipping it and repeatedly
                // selecting a larger folder that still has unused images.
                $usedImages = [];
                $available = $source['images'];
            }
            if ($available === []) {
                $problems[] = basename($folder) . ': no usable image is available.';
                continue;
            }
            $limit = max(1, min(10, (int)($page['folder_images_per_post'] ?? 10)));
            $batch = array_values(array_slice($available, 0, $limit));
            $allJson = json_encode($source['images'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $usedJson = json_encode($usedImages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $batchJson = json_encode($batch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            if ($item) {
                $itemId = (int)$item['id'];
                $update = $db->prepare("UPDATE content_items SET title=?,description=?,category=?,image_url=?,folder_images_json=?,folder_used_images_json=?,folder_batch_images_json=?,status='active' WHERE id=?");
                $update->execute([$source['title'],$source['description'],basename($folder),$batch[0],$allJson,$usedJson,$batchJson,$itemId]);
            } else {
                $existingCounts = array_map(static fn(array $candidate): int => (int)($candidate[3]['folder_rotation_count'] ?? 0), $repeatFolders);
                $startingRotation = $existingCounts === [] ? 0 : min($existingCounts);
                $insert = $db->prepare("INSERT INTO content_items(page_id,content_type,source_type,external_id,title,description,category,image_url,folder_images_json,folder_used_images_json,folder_batch_images_json,folder_rotation_count,status) VALUES(?,'service','server_folder',?,?,?,?,?,?,?,?,?,'active')");
                $insert->execute([(int)$page['id'],$externalId,$source['title'],$source['description'],basename($folder),$batch[0],$allJson,'[]',$batchJson,$startingRotation]);
                $itemId = (int)$db->lastInsertId();
            }
            return ['item_id'=>$itemId,'title'=>$source['title'],'folder'=>basename($folder),'image_count'=>count($batch)];
        }

        $detail = $problems === [] ? '' : ' First invalid folder: ' . $problems[0];
        throw new \RuntimeException('No valid folder image is available.' . $detail);
    }

    /** @return string[] */
    public static function listFolders(array $page):array
    {
        $slug=self::normalizeName((string)($page['folder_source_slug']??''));$root=realpath(self::ROOT);$pageRoot=realpath(self::ROOT.'/'.$slug);
        if($root===false||$pageRoot===false||!is_dir($pageRoot)||!str_starts_with($pageRoot,$root.DIRECTORY_SEPARATOR))return [];
        $names=[];foreach(glob($pageRoot.'/*')?:[] as $folder)if(is_dir($folder))$names[]=basename($folder);
        natcasesort($names);return array_values($names);
    }

    public static function markBatchUsed(PDO $db, int $itemId, array $batch): void
    {
        $query = $db->prepare("SELECT folder_images_json,folder_used_images_json FROM content_items WHERE id=? AND source_type='server_folder'");
        $query->execute([$itemId]);
        $item = $query->fetch();
        if (!$item) return;
        $all = json_decode((string)($item['folder_images_json'] ?? ''), true);
        $used = json_decode((string)($item['folder_used_images_json'] ?? ''), true);
        if (!is_array($all)) $all = [];
        if (!is_array($used)) $used = [];
        $validBatch = array_values(array_filter($batch, static fn($image): bool => is_string($image) && in_array($image, $all, true)));
        $used = array_values(array_unique([...$used, ...$validBatch]));
        $update = $db->prepare('UPDATE content_items SET last_drafted_at=?,folder_used_images_json=?,folder_batch_images_json=NULL,folder_rotation_count=folder_rotation_count+1 WHERE id=?');
        $update->execute([date('Y-m-d H:i:s'),json_encode($used,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$itemId]);
    }

    public static function normalizeName(string $input): string
    {
        $name = trim(str_replace('\\', '/', $input), " \t\n\r\0\x0B/");
        if (str_contains($name, '/')) $name = basename($name);
        if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} _-]{0,63}$/u', $name)) {
            throw new \RuntimeException('Use a folder name up to 64 characters. Letters, numbers, spaces, dash, underscore and Bangla are allowed.');
        }
        return $name;
    }

    /** Resolve a private local-media reference without allowing path traversal. */
    public static function resolve(string $reference): string
    {
        if (!str_starts_with($reference, 'local://')) throw new \RuntimeException('Invalid local image reference.');
        $relative = substr($reference, 8);
        if ($relative === '' || str_contains($relative, "\0")) throw new \RuntimeException('Invalid local image reference.');
        $root = realpath(self::ROOT);
        $path = realpath(self::ROOT . '/' . $relative);
        if ($root === false || $path === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Local image file was not found.');
        }
        $info = @getimagesize($path);
        if ($info === false || !in_array((string)($info['mime'] ?? ''), ['image/jpeg','image/png','image/webp'], true)) {
            throw new \RuntimeException('Local file is not a supported image.');
        }
        return $path;
    }

    private function readFolder(string $pageRoot, string $folder, string $relativeFolder, bool $storyOnly = false): array
    {
        $realFolder = realpath($folder);
        if ($realFolder === false || !str_starts_with($realFolder, $pageRoot . DIRECTORY_SEPARATOR)) throw new \RuntimeException('Unsafe folder path.');
        if ($storyOnly) {
            $title = basename($realFolder);
            $description = '';
        } else {
            $titleFile = $realFolder . '/title.txt';
            $descriptionFile = $realFolder . '/description.txt';
            if (!is_file($titleFile) || !is_file($descriptionFile)) throw new \RuntimeException('title.txt and description.txt are required.');
            $title = $this->readTitle($titleFile, basename($realFolder));
            $description = $this->readText($descriptionFile, 20000);
            if ($title === '' || $description === '') throw new \RuntimeException('title.txt and description.txt cannot be empty.');
        }

        $images = [];
        $skippedImages = 0;
        foreach (glob($realFolder . '/*') ?: [] as $file) {
            if (!is_file($file) || !preg_match('/\.(?:jpe?g|png|webp)$/i', $file)) continue;
            $realFile = realpath($file);
            if ($realFile === false || !str_starts_with($realFile, $realFolder . DIRECTORY_SEPARATOR)) continue;
            $info = @getimagesize($realFile);
            $size = @filesize($realFile);
            if (
                $info === false
                || $size === false
                || $size > 10 * 1024 * 1024
                || (int)($info[0] ?? 0) < 320
                || (int)($info[1] ?? 0) < 320
                || (int)($info[0] ?? 0) > 8192
                || (int)($info[1] ?? 0) > 8192
                || !in_array((string)($info['mime'] ?? ''), ['image/jpeg','image/png','image/webp'], true)
            ) {
                $skippedImages++;
                continue;
            }
            $images[] = 'local://' . $relativeFolder . '/' . basename($realFile);
        }
        natsort($images);
        $images = array_values(array_slice($images, 0, 250));
        if ($images === []) {
            $detail = $skippedImages > 0
                ? ' Existing images were skipped because they exceed 10 MB, are outside 320–8192 pixels, or are unreadable.'
                : '';
            throw new \RuntimeException('Add at least one valid JPG, PNG or WebP image.' . $detail);
        }
        return ['title'=>$title,'description'=>$description,'images'=>$images];
    }

    private function readText(string $path, int $limit): string
    {
        $text = file_get_contents($path, false, null, 0, ($limit * 4) + 1);
        if ($text === false) throw new \RuntimeException('Could not read text file.');
        $text = str_replace("\0", '', $text);
        $text=trim((string)preg_replace('/\R{3,}/u', "\n\n", $text));
        $length=function_exists('mb_strlen')?mb_strlen($text,'UTF-8'):strlen($text);
        if($length>$limit)throw new \RuntimeException(basename($path).' is too large.');
        return $text;
    }

    private function readTitle(string $path,string $fallback):string
    {
        $text=file_get_contents($path,false,null,0,4096);if($text===false)throw new \RuntimeException('Could not read title.txt.');
        $text=str_replace(["\0","\xEF\xBB\xBF"],'',$text);$lines=preg_split('/\R/u',$text)?:[];$title='';
        foreach($lines as $line){$line=trim($line);if($line!==''){$title=$line;break;}}
        if($title==='')$title=$fallback;
        return function_exists('mb_substr')?mb_substr($title,0,250,'UTF-8'):substr($title,0,250);
    }
}
