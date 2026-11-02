<?php
declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Security\Crypto;
use PDO;

final class WooCommerceService
{
    /** Prepare one image batch for one product. */
    public function prepareNext(array $page, string $postType = 'feed'): array
    {
        $base = rtrim((string)($page['woo_store_url'] ?? ''), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL) || !str_starts_with($base, 'https://')) {
            throw new \RuntimeException('A valid HTTPS WooCommerce store URL is required.');
        }
        $key = Crypto::decrypt((string)($page['encrypted_woo_consumer_key'] ?? ''));
        $secret = Crypto::decrypt((string)($page['encrypted_woo_consumer_secret'] ?? ''));
        if ($key === '' || $secret === '') throw new \RuntimeException('WooCommerce API credentials are missing.');

        $db = Database::connection();
        $pageId = (int)$page['id'];
        // Feed and Story have independent 15-day rotation windows. The
        // per-type title check below is the source of truth; last_drafted_at
        // is shared by both types and must not make one consume the other's
        // rotation slot.
        $imagesPerPost = max(1, min(10, (int)($page['woo_images_per_post'] ?? 7)));
        $batchMode = ($page['woo_image_batch_mode'] ?? 'half') === 'fixed' ? 'fixed' : 'half';
        $eligible = [];
        $pageNumber = 1;
        do {
            [$products, $headers] = $this->get($base . '/wp-json/wc/v3/products?' . http_build_query([
                'status' => 'publish', 'stock_status' => 'instock', 'per_page' => 100,
                'page' => $pageNumber, 'orderby' => 'date', 'order' => 'desc',
            ]), $key, $secret);

            foreach ($products as $product) {
                $normalized = $this->normalizeProduct($product);
                if ($normalized === null) continue;
                if (!ContentQualityService::isTitleAvailable($db, $page, $normalized, null, $postType)) continue;
                $existing = $this->findExisting($db, $pageId, $normalized['external_id']);
                $reused = $existing && !empty($existing['last_drafted_at']);

                $start = $existing ? (int)($existing['woo_next_image_index'] ?? 0) : 0;
                if ($start >= count($normalized['images'])) $start = 0;
                $eligible[] = [$normalized, $existing, $start, $reused];
            }
            $totalPages = max(1, (int)($headers['x-wp-totalpages'] ?? 1));
            $pageNumber++;
        } while ($pageNumber <= $totalPages && $pageNumber <= 100);

        if ($eligible !== []) {
            $selected = $eligible[random_int(0, count($eligible) - 1)];
            $remaining = count($selected[0]['images']) - $selected[2];
            $batchSize = $batchMode === 'half'
                ? ($selected[2] === 0 ? (int)ceil(count($selected[0]['images']) / 2) : $remaining)
                : min($imagesPerPost, $remaining);
            $batchSize = max(1, min(10, $batchSize));
            return $this->saveBatch($db, $pageId, $selected[0], $selected[1], $selected[2], $batchSize, $selected[3]);
        }
        throw new \RuntimeException('No eligible product is available yet.');
    }

    private function findExisting(PDO $db, int $pageId, string $externalId): array|false
    {
        $query = $db->prepare("SELECT * FROM content_items WHERE page_id=? AND source_type='woocommerce' AND external_id=?");
        $query->execute([$pageId, $externalId]);
        return $query->fetch();
    }

    private function saveBatch(PDO $db, int $pageId, array $product, array|false $existing, int $start, int $size, bool $reused): array
    {
        $start = max(0, min($start, count($product['images']) - 1));
        $batch = array_slice($product['images'], $start, $size);
        $allJson = json_encode($product['images'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $batchJson = json_encode($batch, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if ($existing) {
            $query = $db->prepare("UPDATE content_items SET title=?,description=?,price=?,features=?,category=?,source_url=?,image_url=?,woo_images_json=?,woo_next_image_index=?,woo_batch_images_json=?,status='active' WHERE id=?");
            $query->execute([$product['title'],$product['description'],$product['price'],$product['features'],$product['category'],$product['source_url'],$batch[0],$allJson,$start,$batchJson,(int)$existing['id']]);
            $itemId = (int)$existing['id'];
        } else {
            $query = $db->prepare("INSERT INTO content_items(content_type,source_type,external_id,title,description,price,features,category,source_url,image_url,woo_images_json,woo_next_image_index,woo_batch_images_json,page_id,status) VALUES('product','woocommerce',?,?,?,?,?,?,?,?,?,?,?,?,'active')");
            $query->execute([$product['external_id'],$product['title'],$product['description'],$product['price'],$product['features'],$product['category'],$product['source_url'],$batch[0],$allJson,$start,$batchJson,$pageId]);
            $itemId = (int)$db->lastInsertId();
        }
        return ['item_id'=>$itemId,'title'=>$product['title'],'reused'=>$reused,'image_count'=>count($batch),'image_start'=>$start + 1];
    }

    private function normalizeProduct(mixed $product): ?array
    {
        if (!is_array($product) || empty($product['id']) || empty($product['name'])) return null;
        $images = [];
        foreach ((array)($product['images'] ?? []) as $image) {
            $url = is_array($image) ? (string)($image['src'] ?? '') : '';
            if (str_starts_with($url, 'https://') && !in_array($url, $images, true)) $images[] = $url;
        }
        if ($images === []) return null;
        $categories = implode(', ', array_filter(array_map(fn($c)=>is_array($c)?$this->plain((string)($c['name']??'')):'',(array)($product['categories']??[]))));
        return [
            'external_id'=>(string)$product['id'], 'title'=>$this->plain((string)$product['name']),
            'description'=>$this->plain((string)($product['description']??'')), 'price'=>(string)($product['price']??''),
            'features'=>$this->plain((string)($product['short_description']??'')), 'category'=>$categories,
            'source_url'=>(string)($product['permalink']??''), 'images'=>$images,
        ];
    }

    private function get(string $url, string $key, string $secret): array
    {
        $headers=[];$ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_USERPWD=>$key.':'.$secret,CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$headers){$len=strlen($line);if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v);}return $len;}]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
        if($raw===false||$status<200||$status>=300)throw new \RuntimeException('WooCommerce request failed (HTTP '.$status.'): '.($error?:substr((string)$raw,0,300)));
        $data=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new \RuntimeException('WooCommerce returned invalid product data.');
        return [$data,$headers];
    }

    private function plain(string $html): string
    {
        $text=html_entity_decode(strip_tags(str_replace(['<br>','<br/>','<br />'],"\n",$html)),ENT_QUOTES|ENT_HTML5,'UTF-8');
        return trim((string)preg_replace('/[ \t]+/',' ',$text));
    }
}
