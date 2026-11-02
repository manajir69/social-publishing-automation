<?php
declare(strict_types=1);

namespace App\Services;

final class BlogAIService
{
    public function __construct(private string $apiKey, private string $model = 'gpt-5-mini')
    {
        if (trim($this->apiKey) === '') throw new \RuntimeException('OpenAI API key is required for researched blog articles.');
        if (trim($this->model) === '') $this->model = 'gpt-5-mini';
    }

    public static function defaultPrompt(): string
    {
        return <<<'PROMPT'
You are a senior content strategist and SEO editor for the configured website and its target audience.

Write original, useful articles for real Bangladeshi homeowners, architects, interior designers and buyers. The main language must be natural professional English. Add one short section titled "বাংলায় সংক্ষেপে" with 3–5 genuinely useful Bangla sentences; do not translate the whole article side by side.

Research before writing. Prefer authoritative manufacturers, standards bodies, established design publications and other trustworthy sources. Never copy their wording. Never invent prices, availability, measurements, certifications, guarantees or claims. If sources disagree, omit the claim.

SEO and structure rules:
- One unique, natural focus keyphrase that matches what a real buyer would search. Local relevance belongs in the article context, not automatically in the headline.
- The title, slug, introduction, meta description and at least one H2 must use the focus keyphrase naturally.
- Keep the SEO title concise with the focus keyphrase near the beginning. Write a unique 120–156 character meta description containing the exact keyphrase.
- Write the article title like a human editor: usually 5–11 words, specific, calm and easy to say aloud.
- Do not add Bangladesh, Dhaka or another location to the article title unless the location itself is essential to the topic supplied by the administrator.
- Do not use a colon, em dash, en dash, pipe or spaced hyphen as a title separator.
- Avoid repetitive headline formulas such as “Buying Guide”, “Practical Guide”, “Complete Guide”, “Ultimate Guide”, “Everything You Need to Know” and “Choose, Size and Install”.
- Do not put an H1 inside the article body; WordPress uses the post title as the only H1.
- Cover the topic thoroughly (normally about 900–1,500 English words when the topic needs it), without padding to reach a word count.
- Use logical H2/H3 sections, short paragraphs, mostly concise active-voice sentences, natural transition words, practical examples and a concise FAQ.
- Use the focus keyphrase and close variations naturally across the article; never force repetition.
- Add 2–4 relevant internal links from the supplied Example Studio link inventory when genuinely useful.
- Add followed outbound links to the most useful research sources in context and finish with a short Sources section.
- Avoid keyword stuffing, generic filler, exaggerated sales language and repetitive conclusions.
- Make the article substantially more useful than a summary of search results.
- Return only the requested JSON. Never wrap JSON in Markdown fences.
PROMPT;
    }

    private static function mandatoryPrompt(): string
    {
        return <<<'PROMPT'
Mandatory system requirements:
- Use web research rather than WooCommerce descriptions and do not invent factual claims.
- The primary article language is English with one short, natural Bangla summary for Bangladesh readers.
- WordPress supplies the only H1 from the post title; content_html must contain no H1.
- Local SEO must come from useful local context, examples, service areas, body copy, metadata and internal links. Do not force “Bangladesh”, “Dhaka” or a city name into the article title or focus keyphrase.
- The article title must sound naturally written by a human, normally 5–11 words. It must not contain a colon, em dash, en dash, pipe or spaced hyphen separator.
- Vary title patterns across articles. Do not repeatedly use “buying guide”, “practical guide”, “complete guide”, “ultimate guide” or comma-separated action lists.
- Return valid JSON only, with no Markdown fence or commentary outside it.
PROMPT;
    }

    /** @param string[] $recentTopics @return array<string,mixed> */
    public function research(string $topicSeeds, array $recentTopics, string $customPrompt = '', string $siteName = 'the website'): array
    {
        $instructions = (trim($customPrompt) !== '' ? trim($customPrompt) : self::defaultPrompt()) . "\n\n" . self::mandatoryPrompt();
        $input = "Website/brand name: " . trim($siteName) . "\n\nChoose one valuable, non-duplicate blog topic and research it on the web.\n\n" .
            "Topic areas supplied by the administrator:\n" . trim($topicSeeds) . "\n\n" .
            "Recently used topics/keyphrases (do not repeat or lightly rephrase):\n" . json_encode($recentTopics, JSON_UNESCAPED_UNICODE) . "\n\n" .
            "Target audience country: Bangladesh. Use that context in research and the article body, but do not automatically put the country or a city in the topic headline or focus keyphrase. The source is web research, not WooCommerce product descriptions. " .
            "Return JSON with: topic, focus_keyphrase, search_intent, facts (array of objects with statement, source_name, source_url), and suggested_sections (array). Use at least 3 reliable source URLs.";

        $response = $this->responses([
            'model' => $this->model,
            'instructions' => $instructions,
            'tools' => [['type' => 'web_search', 'search_context_size' => 'high']],
            'include' => ['web_search_call.action.sources'],
            'input' => $input,
            'store' => false,
        ]);
        $research = $this->decodeJson($this->outputText($response));
        if (trim((string)($research['topic'] ?? '')) === '' || trim((string)($research['focus_keyphrase'] ?? '')) === '') {
            throw new \RuntimeException('AI research did not return a topic and focus keyphrase.');
        }
        $research['sources'] = $this->normalizeSources($research['facts'] ?? [], $this->collectUrls($response));
        if (count($research['sources']) < 2) throw new \RuntimeException('Web research returned too few usable sources.');
        return $research;
    }

    /** @param array<string,mixed> $research @param array<int,array<string,string>> $links @param array<int,array<string,mixed>> $media @return array<string,mixed> */
    public function write(array $research, array $links, array $media, string $customPrompt = '', string $siteName = 'the website'): array
    {
        $instructions = (trim($customPrompt) !== '' ? trim($customPrompt) : self::defaultPrompt()) . "\n\n" . self::mandatoryPrompt();
        $input = "Website/brand name: " . trim($siteName) . "\n\nWrite and self-edit the final article using only the verified research below.\n\nRESEARCH:\n" .
            json_encode($research, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
            "\n\nAVAILABLE INTERNAL LINKS FROM THIS WEBSITE (use 2–4 only when relevant):\n" .
            json_encode(array_slice($links, 0, 60), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
            "\n\nAVAILABLE WORDPRESS MEDIA (select one relevant id, or 0 only if none fits):\n" .
            json_encode(array_slice($media, 0, 60), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
            "\n\nReturn one JSON object with exactly: topic, focus_keyphrase, title, slug, excerpt, content_html, seo_title, meta_description, image_alt, featured_media_id, tags. " .
            "Keep seo_title concise, put the focus keyphrase near its beginning, and keep meta_description between 120 and 156 characters including the exact focus keyphrase. " .
            "content_html may use only p, h2, h3, ul, ol, li, strong, em, a and blockquote. It must contain no h1. tags must be an array of 3–6 short strings.";

        $response = $this->responses([
            'model' => $this->model,
            'instructions' => $instructions,
            'input' => $input,
            'store' => false,
        ]);
        $article = $this->decodeJson($this->outputText($response));
        foreach (['title','focus_keyphrase','content_html','seo_title','meta_description'] as $field) {
            if (trim((string)($article[$field] ?? '')) === '') throw new \RuntimeException('AI article is missing ' . $field . '.');
        }
        $article['title'] = $this->humanTitle((string)$article['title'],120);
        $article['topic'] = trim((string)($article['topic'] ?? $research['topic']));
        $article['focus_keyphrase'] = $this->withoutForcedLocation((string)$article['focus_keyphrase']);
        $article['slug'] = $this->slug((string)($article['slug'] ?? $article['focus_keyphrase']));
        $article['excerpt'] = trim((string)($article['excerpt'] ?? ''));
        $article['content_html'] = $this->normalizeHtml((string)$article['content_html']);
        $article['seo_title'] = $this->humanTitle((string)$article['seo_title'],65);
        $article['meta_description'] = trim((string)$article['meta_description']);
        $article['image_alt'] = trim((string)($article['image_alt'] ?? $article['focus_keyphrase']));
        $allowedMedia = [];
        foreach ($media as $item) $allowedMedia[(int)($item['id'] ?? 0)] = $item;
        $selectedMediaId = max(0, (int)($article['featured_media_id'] ?? 0));
        $article['featured_media_id'] = isset($allowedMedia[$selectedMediaId]) ? $selectedMediaId : 0;
        if ($article['featured_media_id'] > 0 && stripos($article['content_html'], '<img') === false) {
            $selected = $allowedMedia[$article['featured_media_id']];
            $src = htmlspecialchars((string)$selected['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $alt = htmlspecialchars($article['image_alt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $article['content_html'] = '<figure><img src="' . $src . '" alt="' . $alt . '"></figure>' . $article['content_html'];
        }
        $article['tags'] = array_values(array_unique(array_slice(array_filter(array_map('trim', (array)($article['tags'] ?? []))), 0, 6)));
        $article['sources'] = $research['sources'];
        return $article;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function responses(array $payload): array
    {
        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException('OpenAI blog request failed (HTTP ' . $status . '): ' . ($error ?: substr((string)$raw, 0, 900)));
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) throw new \RuntimeException('OpenAI returned invalid JSON.');
        return $decoded;
    }

    /** @param array<string,mixed> $response */
    private function outputText(array $response): string
    {
        if (isset($response['output_text']) && is_string($response['output_text'])) return $response['output_text'];
        $parts = [];
        foreach ((array)($response['output'] ?? []) as $item) {
            foreach ((array)($item['content'] ?? []) as $content) {
                if (($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? null)) $parts[] = $content['text'];
            }
        }
        $text = trim(implode("\n", $parts));
        if ($text === '') throw new \RuntimeException('OpenAI returned no blog text.');
        return $text;
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $text): array
    {
        $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)) ?? $text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false) $text = substr($text, $start, $end - $start + 1);
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) throw new \RuntimeException('AI returned malformed blog JSON.');
        return $decoded;
    }

    /** @param mixed $facts @param string[] $extraUrls @return array<int,array<string,string>> */
    private function normalizeSources(mixed $facts, array $extraUrls): array
    {
        $sources = [];
        foreach ((array)$facts as $fact) {
            if (!is_array($fact)) continue;
            $url = trim((string)($fact['source_url'] ?? ''));
            if (!$this->validUrl($url)) continue;
            $sources[$url] = ['name' => trim((string)($fact['source_name'] ?? parse_url($url, PHP_URL_HOST))), 'url' => $url];
        }
        foreach ($extraUrls as $url) {
            if ($this->validUrl($url) && !isset($sources[$url])) $sources[$url] = ['name' => (string)parse_url($url, PHP_URL_HOST), 'url' => $url];
        }
        return array_values(array_slice($sources, 0, 12, true));
    }

    /** @param mixed $value @return string[] */
    private function collectUrls(mixed $value): array
    {
        $urls = [];
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if ($key === 'url' && is_string($child) && $this->validUrl($child)) $urls[] = $child;
                else $urls = array_merge($urls, $this->collectUrls($child));
            }
        }
        return array_values(array_unique($urls));
    }

    private function validUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with(strtolower($url), 'https://');
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return substr($slug !== '' ? $slug : 'interior-guide-bangladesh-' . date('Ymd'), 0, 190);
    }

    private function humanTitle(string $title,int $limit): string
    {
        $title=html_entity_decode(trim(strip_tags($title)),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $title=$this->withoutForcedLocation($title);
        $title=preg_replace('/\s*(?:[:|—–]|\s-\s)\s*/u',' ',$title)??$title;
        $title=preg_replace('/\s+/u',' ',trim($title," \t\n\r\0\x0B-—–:|"))??$title;
        foreach([
            '/\s+(?:a\s+)?(?:complete|ultimate|practical)\s+guide$/iu',
            '/\s+everything\s+you\s+need\s+to\s+know$/iu',
        ] as $pattern)$title=preg_replace($pattern,'',$title)??$title;
        $title=trim($title);
        if($title==='')throw new \RuntimeException('AI returned an unusable article title.');
        return function_exists('mb_substr')?mb_substr($title,0,$limit,'UTF-8'):substr($title,0,$limit);
    }

    private function withoutForcedLocation(string $value):string
    {
        $value=preg_replace('/\b(?:Bangladesh|Dhaka)\b/iu','',$value)??$value;
        return trim((string)(preg_replace('/\s+/u',' ',$value)??$value)," \t\n\r\0\x0B-—–:|,");
    }

    private function normalizeHtml(string $html): string
    {
        $html = preg_replace_callback('/<\/?h1\b[^>]*>/i', static fn(array $m): string => str_starts_with(strtolower($m[0]), '</') ? '</h2>' : '<h2>', $html) ?? $html;
        return trim($html);
    }
}
