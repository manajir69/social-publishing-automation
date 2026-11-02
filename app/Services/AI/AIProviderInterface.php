<?php
declare(strict_types=1);

namespace App\Services\AI;

interface AIProviderInterface
{
    /** @param array<string,mixed> $content @return array{caption:string,hashtags:string,call_to_action:string} */
    public function generate(array $content, string $systemPrompt): array;
}

