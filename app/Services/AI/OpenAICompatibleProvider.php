<?php
declare(strict_types=1);

namespace App\Services\AI;

final class OpenAICompatibleProvider implements AIProviderInterface
{
    public function __construct(private string $apiKey, private string $model, private string $endpoint) {}

    public static function defaultPrompt(): string
    {
        return <<<'PROMPT'
You are a skilled Bangladeshi Facebook copywriter. Write for ordinary Bangla-speaking customers, not for a product catalogue or search engine.

Create one polished Facebook post from the supplied facts. Follow every rule:
- Write mainly in clear, conversational Bangla script. Use familiar English product names or technical terms only when they sound more natural than a forced translation.
- Never translate word-for-word. Avoid stiff, formal, literary or machine-translated Bangla and avoid unusually long sentences.
- Turn the raw source title into a fresh, natural and attention-friendly opening.
- Do not copy the source description. Understand it, then write an original version in natural Bangla.
- Follow writing_style_variant to vary the structure from post to post: direct_conversation, customer_problem, practical_advice, or real_space_observation. Do not reuse one fixed template.
- Write 2 to 4 short paragraphs separated by a blank line. Each paragraph must contain only 1 or 2 short sentences. Do not write one large paragraph.
- Mention only 2 or 3 useful customer-facing facts. Never dump every size, thickness, coverage, material, origin, design name, feature or specification from the source.
- Include at most two physical measurements/specifications, and only when they are essential for a buying decision.
- Usually keep the caption between 55 and 90 words, but vary the length naturally instead of forcing every post into the same size.
- Do not use emojis, decorative symbols, bullets or numbered lists.
- Use Bangla script and familiar English terms only. Never output Cyrillic, Russian or any unrelated writing system.
- Vary the opening and sentence rhythm. The post must sound like a real local business wrote it, not a catalogue or an AI template.
- Use natural, slightly informal Bangladeshi business language. Avoid perfectly symmetrical sentences, exaggerated marketing claims and over-polished catalogue wording.
- If caption_examples are supplied, imitate their voice, pacing and everyday phrasing. Never copy product facts, numbers, prices or claims from an example unless the current source also supplies them.
- Do not put hashtags, URL or call to action inside caption.
- call_to_action must be one warm, natural Bangla sentence that invites the customer to contact the business or view the product. Do not invent contact details.
- If post_instruction is supplied, follow it in every generated post. Preserve supplied phone/WhatsApp numbers exactly and turn the instruction into a natural call_to_action; never silently omit or change it.
- hashtags must contain only 3 to 5 short, highly relevant hashtags on one line.
- Use only supplied facts. Never invent price, specifications, stock, discount, warranty, delivery promise or offer.
- Return only a valid JSON object with exactly these string fields: caption, hashtags, call_to_action.
PROMPT;
    }

    public function generate(array $content, string $systemPrompt): array
    {
        $systemPrompt = trim($systemPrompt);
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
        $instructions = $systemPrompt === ''
            ? self::defaultPrompt()
            : $systemPrompt . "\n\n" . $mandatory;
        $content = $this->compactContent($content);
        $data = HttpJsonClient::post($this->endpoint, ['Authorization' => 'Bearer ' . $this->apiKey], [
            'model' => $this->model,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
            ],
        ]);
        $text = $data['choices'][0]['message']['content'] ?? '';
        $result = json_decode($text, true);
        if (!is_array($result) || empty($result['caption'])) throw new \RuntimeException('AI returned an invalid response.');
        return PostCopyFormatter::normalize([
            'caption' => trim((string) $result['caption']),
            'hashtags' => trim((string) ($result['hashtags'] ?? '')),
            'call_to_action' => trim((string) ($result['call_to_action'] ?? '')),
        ]);
    }

    /** @return array<string,mixed> */
    private function compactContent(array $content): array
    {
        if(trim((string)($content['writing_style_variant']??''))==='')$content['writing_style_variant']=PostCopyFormatter::writingStyleVariant($content);
        $limits = ['page_name'=>120, 'title'=>300, 'description'=>1800, 'price'=>100, 'features'=>900, 'category'=>300, 'source_url'=>1000, 'post_instruction'=>500, 'caption_examples'=>1800, 'writing_style_variant'=>40];
        foreach ($limits as $field => $limit) {
            if (!isset($content[$field]) || !is_string($content[$field])) continue;
            $value = trim((string)preg_replace('/\s+/u', ' ', $content[$field]));
            $content[$field] = function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
        }
        return $content;
    }
}
