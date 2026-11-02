<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Security\Crypto;

final class AIProviderFactory
{
    public static function make(array $page): AIProviderInterface
    {
        $key = Crypto::decrypt((string) ($page['encrypted_ai_api_key'] ?? ''));
        $model = (string) ($page['ai_model'] ?? '');
        $provider = (string)($page['ai_provider'] ?? 'none');
        if ($key === '') {
            $envName = ['openai'=>'OPENAI_API_KEY','anthropic'=>'ANTHROPIC_API_KEY','qwen'=>'DASHSCOPE_API_KEY'][$provider] ?? '';
            if ($envName !== '') $key = trim((string)\env($envName, ''));
        }
        return match ($provider) {
            'openai' => new OpenAICompatibleProvider($key, $model ?: 'gpt-5-mini', 'https://api.openai.com/v1/chat/completions'),
            'anthropic' => new AnthropicProvider($key, $model ?: 'claude-sonnet-4-6'),
            'qwen' => new OpenAICompatibleProvider($key, $model ?: 'qwen-plus', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions'),
            default => throw new \RuntimeException('No AI provider is configured for this page.'),
        };
    }
}
