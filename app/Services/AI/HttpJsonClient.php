<?php
declare(strict_types=1);

namespace App\Services\AI;

final class HttpJsonClient
{
    /** @param array<string,string> $headers @param array<string,mixed> $body */
    public static function post(string $url, array $headers, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers)),
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException('AI request failed (HTTP ' . $status . '): ' . ($error ?: substr((string) $raw, 0, 300)));
        }
        return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    }
}

