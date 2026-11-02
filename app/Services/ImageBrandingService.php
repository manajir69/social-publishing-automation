<?php
declare(strict_types=1);

namespace App\Services;

final class ImageBrandingService
{
    private const LOGO_ROOT = BASE_PATH . '/storage/branding';
    private const POSITIONS = [
        'top-left', 'top-center', 'top-right',
        'middle-left', 'middle-center', 'middle-right',
        'bottom-left', 'bottom-center', 'bottom-right',
    ];

    /** @return string[] */
    public static function positions(): array
    {
        return self::POSITIONS;
    }

    /**
     * Save a validated Page logo and return its portable path relative to
     * storage/branding. The original upload is never placed in page-content.
     *
     * @param array<string,mixed> $upload
     */
    public static function saveLogo(int $pageId, array $upload): string
    {
        if ($pageId < 1) throw new \RuntimeException('Save the Facebook Page before uploading a logo.');
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) throw new \RuntimeException('Choose a logo image first.');
        if ($error !== UPLOAD_ERR_OK) throw new \RuntimeException('Logo upload failed with code ' . $error . '.');
        $tmp = (string)($upload['tmp_name'] ?? '');
        $size = (int)($upload['size'] ?? 0);
        if ($tmp === '' || !is_file($tmp) || $size < 1 || $size > 5 * 1024 * 1024) {
            throw new \RuntimeException('Logo must be a valid image no larger than 5 MB.');
        }
        $data = file_get_contents($tmp);
        $info = $data === false ? false : @getimagesizefromstring($data);
        $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
        $extensions = ['image/png'=>'png', 'image/jpeg'=>'jpg', 'image/webp'=>'webp'];
        if ($data === false || !isset($extensions[$mime])) throw new \RuntimeException('Logo must be a PNG, JPG or WebP image.');
        if ((int)($info[0] ?? 0) < 32 || (int)($info[1] ?? 0) < 32 || (int)($info[0] ?? 0) > 4096 || (int)($info[1] ?? 0) > 4096) {
            throw new \RuntimeException('Logo dimensions must be between 32 and 4096 pixels.');
        }

        $relativeDir = 'page-' . $pageId;
        $dir = self::LOGO_ROOT . '/' . $relativeDir;
        self::ensureDirectory($dir);
        $relative = $relativeDir . '/logo-' . substr(hash('sha256', $data), 0, 16) . '.' . $extensions[$mime];
        $destination = self::LOGO_ROOT . '/' . $relative;
        if (!is_file($destination) && file_put_contents($destination, $data, LOCK_EX) === false) {
            throw new \RuntimeException('Could not save the Page logo.');
        }
        return $relative;
    }

    public static function deleteLogo(?string $relative): void
    {
        $path = self::logoPath((string)$relative);
        if ($path !== null && is_file($path)) @unlink($path);
    }

    /** @return array{data:string,mime:string}|null */
    public static function readLogo(string $relative): ?array
    {
        $path = self::logoPath($relative);
        if ($path === null) return null;
        $data = file_get_contents($path);
        $info = $data === false ? false : @getimagesizefromstring($data);
        if ($data === false || $info === false) return null;
        return ['data'=>$data, 'mime'=>(string)($info['mime'] ?? 'application/octet-stream')];
    }

    /** @param string[] $references @return string[] */
    public static function applyBatch(array $page, array $references, bool $story = false): array
    {
        if (empty($page['folder_branding_enabled'])) return $references;
        $logo = self::logoPath((string)($page['folder_logo_path'] ?? ''));
        $text = trim((string)($page['folder_brand_text'] ?? ''));
        if ($logo === null && $text === '') return $references;
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            throw new \RuntimeException('PHP GD is required for image branding. No external image API is used.');
        }

        $branded = [];
        foreach ($references as $reference) {
            if (!is_string($reference) || !str_starts_with($reference, 'local://')) {
                $branded[] = $reference;
                continue;
            }
            $branded[] = self::apply($page, $reference, $logo, $text, $story);
        }
        return $branded;
    }

    private static function apply(array $page, string $reference, ?string $logoPath, string $text, bool $story): string
    {
        $sourcePath = FolderContentService::resolve($reference);
        $signature = hash('sha256', json_encode([
            'renderer_version'=>5,
            'source'=>$reference,
            'mtime'=>filemtime($sourcePath) ?: 0,
            'size'=>filesize($sourcePath) ?: 0,
            'logo'=>$logoPath ? hash_file('sha256', $logoPath) : '',
            'logo_size'=>(int)($page['folder_logo_size_pct'] ?? 12),
            'logo_position'=>(string)($page['folder_logo_position'] ?? 'top-right'),
            'text'=>$text,
            'text_color'=>(string)($page['folder_text_color'] ?? '#FFFFFF'),
            'text_background_enabled'=>!array_key_exists('folder_text_background_enabled',$page) || !empty($page['folder_text_background_enabled']),
            'text_background_color'=>(string)($page['folder_text_background_color'] ?? '#050F19'),
            'text_background_opacity'=>(int)($page['folder_text_background_opacity'] ?? 35),
            'text_position'=>(string)($page['folder_text_position'] ?? 'bottom-center'),
            'text_opacity'=>(int)($page['folder_text_opacity'] ?? 90),
            'text_size'=>(int)($page['folder_text_size_pct'] ?? 3),
            'story'=>$story,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $pageId = max(1, (int)($page['id'] ?? $page['page_id'] ?? 0));
        $relative = '.branded/page-' . $pageId . '/' . substr($signature, 0, 32) . '.jpg';
        $destination = BASE_PATH . '/page-content/' . $relative;
        if (is_file($destination) && (filesize($destination) ?: 0) > 0) return 'local://' . $relative;

        $data = file_get_contents($sourcePath);
        $source = $data === false ? false : @imagecreatefromstring($data);
        if ($source === false) throw new \RuntimeException('A folder image could not be opened for branding.');
        $source = self::applyExifOrientation($source, $sourcePath);
        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
        imagedestroy($source);
        imagealphablending($canvas, true);

        if ($logoPath !== null) self::drawLogo($canvas, $logoPath, $page, $story);
        if ($text !== '') self::drawText($canvas, $text, $page, $story);

        self::ensureDirectory(dirname($destination));
        $saved = false;
        foreach ([90, 84, 76] as $quality) {
            if (!imagejpeg($canvas, $destination, $quality)) continue;
            if ((filesize($destination) ?: PHP_INT_MAX) <= 8 * 1024 * 1024) {$saved = true; break;}
        }
        imagedestroy($canvas);
        if (!$saved) {
            @unlink($destination);
            throw new \RuntimeException('The branded image could not be kept below 8 MB.');
        }
        return 'local://' . $relative;
    }

    /** @param \GdImage|resource $canvas */
    private static function drawLogo($canvas, string $logoPath, array $page, bool $story): void
    {
        $data = file_get_contents($logoPath);
        $logo = $data === false ? false : @imagecreatefromstring($data);
        if ($logo === false) throw new \RuntimeException('The saved Page logo is unreadable.');
        $width = imagesx($canvas); $height = imagesy($canvas);
        $logoWidth = imagesx($logo); $logoHeight = imagesy($logo);
        $percent = max(5, min(40, (int)($page['folder_logo_size_pct'] ?? 12)));
        $targetWidth = max(32, (int)round($width * ($percent / 100)));
        $targetHeight = max(16, (int)round($logoHeight * ($targetWidth / max(1, $logoWidth))));
        $maxHeight = (int)round($height * .28);
        if ($targetHeight > $maxHeight) {
            $targetHeight = $maxHeight;
            $targetWidth = max(16, (int)round($logoWidth * ($targetHeight / max(1, $logoHeight))));
        }
        $position = self::position((string)($page['folder_logo_position'] ?? 'top-right'));
        [$x,$y] = self::coordinates($width, $height, $targetWidth, $targetHeight, $position, $story);
        imagealphablending($logo, true);
        imagesavealpha($logo, true);
        imagecopyresampled($canvas, $logo, $x, $y, 0, 0, $targetWidth, $targetHeight, $logoWidth, $logoHeight);
        imagedestroy($logo);
    }

    /** One explicit, non-empty input line makes one pill; never drop a line. */
    private static function drawText($canvas, string $text, array $page, bool $story, int $scale = 4): void
    {
        if (!function_exists('imagettftext')) throw new \RuntimeException('Image branding requires PHP GD with FreeType for Unicode text and icons.');
        $lines = BrandingText::lines($text);
        if (!$lines) return;
        $width = imagesx($canvas); $height = imagesy($canvas);
        $position = self::position((string)($page['folder_text_position'] ?? 'bottom-center'));
        $fontSize = max(1, min(120, min($width, $height) * max(1, min(8, (int)($page['folder_text_size_pct'] ?? 3))) / 100));
        $maxWidth = max(1, min($width * .88, $width - 2 * max(16, round($width * .035))));
        $maxHeight = max(1, $height - 2 * max(16, round($height * ($story ? .085 : .035))));
        $runs = array_map([BrandingText::class, 'runs'], $lines);
        // Measure and rasterize the small branding layer at 4x, then downsample.
        // Leave the original photo and logo at their native resolution.
        $fontSize *= $scale; $maxWidth *= $scale; $maxHeight *= $scale;
        // Fit the complete group, including long unbroken links and many lines.
        do {
            $paddingX = max(2 * $scale, (int)round($fontSize * .65));
            $paddingY = max($scale, (int)round($fontSize * .35));
            $gap = max($scale, (int)round($fontSize * .3));
            $stroke = max($scale, (int)round($fontSize * .045));
            $items = []; $blockWidth = 0; $blockHeight = 0;
            foreach ($runs as $lineRuns) {
                $item = BrandingText::measure($lineRuns, $fontSize);
                $item['boxWidth'] = $item['width'] + 2 * ($paddingX + $stroke);
                $item['boxHeight'] = max((int)ceil($fontSize * 1.25), $item['height']) + 2 * ($paddingY + $stroke);
                $items[] = $item;
                $blockWidth += $item['boxWidth'];
                $blockHeight = max($blockHeight, $item['boxHeight']);
            }
            $blockWidth += (count($items) - 1) * $gap;
            if ($blockWidth <= $maxWidth && $blockHeight <= $maxHeight) break;
            $fontSize -= .5 * $scale;
            if ($fontSize < $scale) throw new \RuntimeException('Branding text cannot fit this image. Use fewer or shorter lines.');
        } while (true);
        $targetWidth = (int)ceil($blockWidth / $scale);
        $targetHeight = (int)ceil($blockHeight / $scale);
        // Bound extra memory for unusually large originals, without resizing them.
        if ($targetWidth * $targetHeight * $scale * $scale > 8000000 && $scale > 1) {
            self::drawText($canvas, $text, $page, $story, max(1, intdiv($scale, 2)));
            return;
        }
        [$destinationX,$destinationY] = self::coordinates($width, $height, $targetWidth, $targetHeight, $position, $story);
        $layer = imagecreatetruecolor($targetWidth * $scale, $targetHeight * $scale);
        if ($layer === false) throw new \RuntimeException('Could not allocate the branding layer.');
        imagealphablending($layer, false);
        imagesavealpha($layer, true);
        imagefill($layer, 0, 0, imagecolorallocatealpha($layer, 0, 0, 0, 127));
        $x = 0; $y = 0;
        $opacity = max(10, min(100, (int)($page['folder_text_opacity'] ?? 90)));
        $backgroundOpacity = max(0, min(100, (int)($page['folder_text_background_opacity'] ?? 35)));
        $backgroundEnabled = !array_key_exists('folder_text_background_enabled',$page) || !empty($page['folder_text_background_enabled']);
        [$r,$g,$b] = self::hexColor((string)($page['folder_text_color'] ?? '#FFFFFF'), [255,255,255]);
        $foreground = imagecolorallocatealpha($layer,$r,$g,$b,127-(int)round($opacity*1.27));
        [$r,$g,$b] = self::hexColor((string)($page['folder_text_background_color'] ?? '#050F19'), [5,15,25]);
        $background = imagecolorallocatealpha($layer,$r,$g,$b,127-(int)round($backgroundOpacity*1.27));
        try {
            foreach ($items as $item) {
                imagealphablending($layer, false);
                $pillX = $x;
                $pillY = $y + (int)(($blockHeight - $item['boxHeight']) / 2);
                self::drawPill($layer,(int)$pillX,$pillY,$item['boxWidth'],$item['boxHeight'],$stroke,$foreground,$background,$backgroundEnabled && $backgroundOpacity > 0);
                $textX = $pillX + $paddingX + $stroke;
                $baseline = $pillY + (int)(($item['boxHeight']-$item['height'])/2) - $item['top'];
                imagealphablending($layer, true);
                foreach ($item['runs'] as $run) {
                    imagettftext($layer,$fontSize*.75,0,(int)round($textX-$run['left']),$baseline,$foreground,BrandingText::gdFont($run['font']),BrandingText::gdText($run['text']));
                    $textX += $run['width'];
                }
                $x += $item['boxWidth'] + $gap;
            }
            imagealphablending($canvas, true);
            imagecopyresampled($canvas, $layer, $destinationX, $destinationY, 0, 0,
                $targetWidth, $targetHeight, $targetWidth * $scale, $targetHeight * $scale);
        } finally {
            unset($layer);
        }
    }

    /** Scanlines avoid overlapping alpha at rounded corners or under the border. */
    private static function drawPill($canvas, int $x, int $y, int $width, int $height, int $stroke, int $foreground, int $background, bool $fill): void
    {
        $radius = min($height / 2, $width / 2);
        for ($row = 0; $row < $height; $row++) {
            $dy = max(0, $radius - ($row + .5), ($row + .5) - ($height - $radius));
            $inset = (int)ceil($radius - sqrt(max(0, $radius*$radius-$dy*$dy)));
            $left = $x + $inset; $right = $x + $width - 1 - $inset;
            $innerRadius = max(0, $radius-$stroke);
            $innerDy = max(0, $radius-($row+.5), ($row+.5)-($height-$radius));
            if ($row < $stroke || $row >= $height-$stroke || $innerDy >= $innerRadius) {
                if ($left <= $right) imageline($canvas,$left,$y+$row,$right,$y+$row,$foreground);
                continue;
            }
            $innerInset = (int)ceil($radius-sqrt(max(0,$innerRadius*$innerRadius-$innerDy*$innerDy)));
            $innerLeft = $x+$innerInset; $innerRight = $x+$width-1-$innerInset;
            if ($innerLeft>$left) imageline($canvas,$left,$y+$row,$innerLeft-1,$y+$row,$foreground);
            if ($innerRight<$right) imageline($canvas,$innerRight+1,$y+$row,$right,$y+$row,$foreground);
            if ($fill && $innerLeft <= $innerRight) imageline($canvas,$innerLeft,$y+$row,$innerRight,$y+$row,$background);
        }
    }

    /** @param array{0:int,1:int,2:int} $fallback @return array{0:int,1:int,2:int} */
    private static function hexColor(string $value, array $fallback): array
    {
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) return $fallback;
        return [hexdec(substr($value,1,2)),hexdec(substr($value,3,2)),hexdec(substr($value,5,2))];
    }

    /** Apply camera orientation before GD strips JPEG metadata. */
    private static function applyExifOrientation($image,string $path)
    {
        if(!function_exists('exif_read_data'))return $image;
        $info=@getimagesize($path);if((string)($info['mime']??'')!=='image/jpeg')return $image;
        $exif=@exif_read_data($path,'IFD0',true);$orientation=(int)($exif['IFD0']['Orientation']??$exif['Orientation']??1);
        if(in_array($orientation,[2,4,5,7],true)&&function_exists('imageflip'))imageflip($image,in_array($orientation,[2,5],true)?IMG_FLIP_HORIZONTAL:IMG_FLIP_VERTICAL);
        $angle=match($orientation){3=>180,5,6=>-90,7,8=>90,default=>0};
        if($angle!==0){$rotated=imagerotate($image,$angle,0);if($rotated!==false){imagedestroy($image);$image=$rotated;}}
        return $image;
    }

    /** @return array{0:int,1:int} */
    private static function coordinates(int $width, int $height, int $itemWidth, int $itemHeight, string $position, bool $story): array
    {
        $marginX = max(16, (int)round($width * .035));
        $marginY = max(16, (int)round($height * ($story ? .085 : .035)));
        $x = str_ends_with($position, 'left') ? $marginX : (str_ends_with($position, 'right') ? $width - $itemWidth - $marginX : (int)(($width - $itemWidth) / 2));
        $y = str_starts_with($position, 'top') ? $marginY : (str_starts_with($position, 'bottom') ? $height - $itemHeight - $marginY : (int)(($height - $itemHeight) / 2));
        return [max(0, $x), max(0, $y)];
    }

    private static function position(string $position): string
    {
        return in_array($position, self::POSITIONS, true) ? $position : 'bottom-center';
    }

    private static function logoPath(string $relative): ?string
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) return null;
        $root = realpath(self::LOGO_ROOT);
        $path = realpath(self::LOGO_ROOT . '/' . $relative);
        if ($root === false || $path === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) return null;
        $info = @getimagesize($path);
        return $info !== false && in_array((string)($info['mime'] ?? ''), ['image/png','image/jpeg','image/webp'], true) ? $path : null;
    }

    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the image-branding directory.');
        }
    }
}
