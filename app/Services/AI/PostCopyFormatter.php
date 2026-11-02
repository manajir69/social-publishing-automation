<?php
declare(strict_types=1);

namespace App\Services\AI;

final class PostCopyFormatter
{
    /** Rotate natural structures without making a second AI request. */
    public static function writingStyleVariant(array $content): string
    {
        $variants=['direct_conversation','customer_problem','practical_advice','real_space_observation'];
        $seed=implode('|',[
            (string)($content['page_name']??''),
            (string)($content['title']??''),
            date('Y-m-d'),
        ]);
        $index=(int)sprintf('%u',crc32($seed))%count($variants);
        return $variants[$index];
    }

    /**
     * @param array{caption?:mixed,hashtags?:mixed,call_to_action?:mixed} $copy
     * @return array{caption:string,hashtags:string,call_to_action:string}
     */
    public static function normalize(array $copy, string $fallbackHashtags = ''): array
    {
        $caption = self::cleanGeneratedText((string)($copy['caption'] ?? ''));
        $hashtags = trim((string)($copy['hashtags'] ?? ''));
        $callToAction = self::cleanGeneratedText((string)($copy['call_to_action'] ?? ''));

        // Occasionally a model puts hashtags inside the caption despite the JSON fields.
        // Move them to the dedicated hashtag line so the published post stays readable.
        $captionHashtags = self::extractHashtags($caption);
        $caption = trim((string)preg_replace('/(?:^|\s)#[\p{L}\p{N}_]+/u', '', $caption));
        $caption = (string)preg_replace("/[ \t]+\n/u", "\n", $caption);
        $caption = (string)preg_replace("/\n{3,}/u", "\n\n", $caption);

        $tags = array_merge(
            self::extractHashtags($hashtags),
            $captionHashtags
        );
        if ($tags === []) {
            $tags = self::extractHashtags($fallbackHashtags);
        }

        $unique = [];
        foreach ($tags as $tag) {
            $key = self::lower($tag);
            if (!isset($unique[$key])) {
                $unique[$key] = $tag;
            }
            if (count($unique) === 5) break;
        }

        return [
            'caption' => trim($caption),
            'hashtags' => implode(' ', array_values($unique)),
            'call_to_action' => trim((string)preg_replace('/\s+/u', ' ', $callToAction)),
        ];
    }

    /** @return list<string> */
    private static function extractHashtags(string $text): array
    {
        preg_match_all('/#[\p{L}\p{N}_]+/u', $text, $matches);
        return array_values($matches[0] ?? []);
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function cleanGeneratedText(string $value): string
    {
        // Keep Bangla and familiar English copy human-looking even if a model
        // ignores the prompt and inserts emoji or an unrelated script.
        $value=(string)preg_replace('/[\x{1F1E6}-\x{1F1FF}\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u','',$value);
        $value=(string)preg_replace('/\p{Cyrillic}+/u','',$value);
        $value=(string)preg_replace('/[ \t]{2,}/u',' ',$value);
        $value=(string)preg_replace("/[ \t]+\n/u","\n",$value);
        return trim($value);
    }
}
