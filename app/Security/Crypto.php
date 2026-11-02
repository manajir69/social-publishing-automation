<?php
declare(strict_types=1);

namespace App\Security;

final class Crypto
{
    private static function key(): string
    {
        $hex = (string) \config('key', '');
        if (!preg_match('/^[a-f0-9]{64}$/i', $hex)) {
            throw new \RuntimeException('APP_KEY must contain exactly 64 hexadecimal characters.');
        }
        return hex2bin($hex);
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') return '';
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(string $encoded): string
    {
        if ($encoded === '') return '';
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Invalid encrypted value.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        if ($plain === false) throw new \RuntimeException('Could not decrypt credential.');
        return $plain;
    }
}

