<?php
declare(strict_types=1);
namespace App\Services;

/** Shared font coverage for GD and the browser's monochrome Unicode preview. */
final class BrandingText
{
    public const ROOT = BASE_PATH . '/public/assets/branding-fonts/';

    public static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), static fn($line) => $line !== ''));
    }

    public static function runs(string $text): array
    {
        static $coverage = null;
        $coverage ??= json_decode((string)file_get_contents(self::ROOT . 'coverage.json'), true, 512, JSON_THROW_ON_ERROR);
        $runs = [];
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            // Presentation selectors do not change these single-color glyphs.
            if ($char === "\u{FE0F}" || $char === "\u{FE0E}") continue;
            $point = self::codePoint($char);
            $font = null;
            foreach ($coverage as $file => $ranges) {
                foreach ($ranges as [$first, $last]) {
                    if ($point >= $first && $point <= $last) { $font = $file; break 2; }
                }
            }
            if ($font === null || $point === 0x200D) throw new \RuntimeException('Branding text contains an unsupported character (U+' . strtoupper(dechex($point)) . '). Use a standalone Unicode icon, such as ☎, ✆, ◎, 🌐, 📞 or 📍.');
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last]['font'] === $font) $runs[$last]['text'] .= $char;
            else $runs[] = ['font'=>$font, 'text'=>$char];
        }
        return $runs;
    }

    public static function validate(string $text): void
    {
        foreach (self::lines($text) as $line) self::runs($line);
    }

    /** Use BMP glyph aliases: portable across bundled and external GD builds. */
    public static function gdText(string $text): string
    {
        static $aliases = null;
        $aliases ??= json_decode((string)file_get_contents(self::ROOT . 'gd-map.json'), true, 512, JSON_THROW_ON_ERROR);
        // Preserve literal entity-looking user text; GD decodes &#38; once.
        return strtr(str_replace('&', '&#38;', $text), $aliases);
    }

    public static function gdFont(string $font): string
    {
        return self::ROOT . match ($font) {
            'NotoSansSymbols2-Regular.ttf' => 'BrandingSymbolsGD.ttf',
            'NotoEmoji.ttf' => 'BrandingEmojiGD.ttf',
            default => $font,
        };
    }

    private static function codePoint(string $char): int
    {
        $bytes = array_values(unpack('C*', $char));
        $point = $bytes[0];
        if ($point >= 240) return (($point & 7) << 18) | (($bytes[1] & 63) << 12) | (($bytes[2] & 63) << 6) | ($bytes[3] & 63);
        if ($point >= 224) return (($point & 15) << 12) | (($bytes[1] & 63) << 6) | ($bytes[2] & 63);
        if ($point >= 192) return (($point & 31) << 6) | ($bytes[1] & 63);
        return $point;
    }

    /** Pixel coordinates use 96 dpi; GD font sizes use 72 dpi points. */
    public static function measure(array $runs, float $pixels): array
    {
        $width = 0; $top = 0; $bottom = 0; $measured = [];
        foreach ($runs as $run) {
            $box = imagettfbbox($pixels * .75, 0, self::gdFont($run['font']), self::gdText($run['text']));
            if ($box === false) throw new \RuntimeException('Could not measure branding font.');
            $left = min($box[0], $box[2], $box[4], $box[6]);
            $right = max($box[0], $box[2], $box[4], $box[6]);
            $run['left'] = $left; $run['width'] = $right - $left;
            $measured[] = $run; $width += $run['width'];
            $top = min($top, $box[5], $box[7]); $bottom = max($bottom, $box[1], $box[3]);
        }
        return ['runs'=>$measured, 'width'=>$width, 'top'=>$top, 'height'=>$bottom-$top];
    }
}
