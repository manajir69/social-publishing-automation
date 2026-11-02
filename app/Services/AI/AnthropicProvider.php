<?php
declare(strict_types=1);

namespace App\Services\AI;

final class AnthropicProvider implements AIProviderInterface
{
    public function __construct(private string $apiKey, private string $model = 'claude-sonnet-4-6') {}

    public function generate(array $content, string $systemPrompt): array
    {
        if (trim($this->apiKey) === '') throw new \RuntimeException('Claude API key is required for this Facebook Page.');
        $mandatory = <<<'PROMPT'
Mandatory output requirements:
- Use only facts supplied in the source. Never invent prices, specifications, stock, offers, contact details or promises.
- If post_instruction is supplied, follow it, preserve its phone/WhatsApp numbers exactly, and include it naturally in call_to_action.
- Follow writing_style_variant and use a visibly different natural structure for direct_conversation, customer_problem, practical_advice or real_space_observation. Do not reuse one fixed template.
- Write the caption in 2 to 4 short paragraphs separated by blank lines; each paragraph may have only 1 or 2 short sentences.
- Usually keep the caption between 55 and 90 words, with natural variation. Select only 2 or 3 useful facts and include at most two essential physical measurements/specifications.
- Never copy or compress the full product description into the caption.
- Do not use emojis, decorative symbols, bullets or numbered lists.
- Use Bangla script and familiar English terms only; never output Cyrillic, Russian or any unrelated writing system.
- Vary the opening and avoid obvious AI phrases such as "perfect blend", "smart solution", "elevate", "transform your space", "নতুন মাত্রা" or "বদলে দিন".
- Write like a real Bangladeshi shop or service team: conversational, clear and slightly informal, without fake enthusiasm or catalogue polish.
- If caption_examples are supplied, follow their voice and pacing, but never copy facts or contact details from them unless those facts are present in the current source or post_instruction.
- Keep the meaning of post_instruction, but vary the CTA sentence naturally from post to post. Preserve every supplied phone/WhatsApp number exactly.
- Return only a valid JSON object with exactly these string fields: caption, hashtags, call_to_action.
- Do not add markdown fences or any text outside the JSON object.
PROMPT;
        $instructions = trim($systemPrompt) === ''
            ? OpenAICompatibleProvider::defaultPrompt()
            : trim($systemPrompt) . "\n\n" . $mandatory;
        $data = HttpJsonClient::post('https://api.anthropic.com/v1/messages', [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ], [
            'model' => $this->model,
            'max_tokens' => 1600,
            'system' => $instructions,
            'messages' => [[
                'role' => 'user',
                'content' => json_encode($this->compactContent($content), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]],
        ]);
        $parts = [];
        foreach ((array)($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) $parts[] = $block['text'];
        }
        $text = trim(implode("\n", $parts));
        $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false) $text = substr($text, $start, $end - $start + 1);
        $result = json_decode($text, true);
        if (!is_array($result) || empty($result['caption'])) throw new \RuntimeException('Claude returned an invalid Facebook caption response.');
        return PostCopyFormatter::normalize([
            'caption' => trim((string)$result['caption']),
            'hashtags' => trim((string)($result['hashtags'] ?? '')),
            'call_to_action' => trim((string)($result['call_to_action'] ?? '')),
        ]);
    }

    /** @return array<string,mixed> */
    private function compactContent(array $content): array
    {
        if(trim((string)($content['writing_style_variant']??''))==='')$content['writing_style_variant']=PostCopyFormatter::writingStyleVariant($content);
        $limits = ['page_name'=>120,'title'=>300,'description'=>1800,'price'=>100,'features'=>900,'category'=>300,'source_url'=>1000,'post_instruction'=>500,'caption_examples'=>1800,'writing_style_variant'=>40];
        foreach ($limits as $field => $limit) {
            if (!isset($content[$field]) || !is_string($content[$field])) continue;
            $value = trim((string)preg_replace('/\s+/u', ' ', $content[$field]));
            $content[$field] = function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
        }
        return $content;
    }
}
