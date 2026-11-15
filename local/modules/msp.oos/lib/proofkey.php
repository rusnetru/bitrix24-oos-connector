<?php

declare(strict_types=1);

namespace Msp\Oos;

/**
 * Проверка подписи запросов OOS (заголовки X-WOPI-Proof, X-WOPI-ProofOld, X-WOPI-TimeStamp).
 * Подтверждает, что запрос пришёл именно от нашей фермы OOS.
 */
final class ProofKey
{
    /** Разница между 0001-01-01 и 1970-01-01 в тиках .NET (100 нс). */
    private const EPOCH_TICKS = 621355968000000000;
    private const MAX_AGE = 1200;

    /**
     * @param array<string, string> $keys modulus, exponent, oldmodulus, oldexponent из discovery
     */
    public static function verify(
        string $token,
        string $url,
        string $timestamp,
        string $proof,
        string $oldProof,
        array $keys
    ): bool {
        if ($timestamp === '' || $proof === '' || !ctype_digit($timestamp)) {
            return false;
        }

        $ticks = (int)$timestamp;
        $unixTime = intdiv($ticks - self::EPOCH_TICKS, 10000000);
        if (abs(time() - $unixTime) > self::MAX_AGE) {
            return false;
        }

        $upperUrl = strtoupper($url);
        $expected = pack('N', strlen($token)) . $token
            . pack('N', strlen($upperUrl)) . $upperUrl
            . pack('N', 8) . pack('J', $ticks);

        $current = self::publicKey($keys['modulus'] ?? '', $keys['exponent'] ?? '');
        $old = self::publicKey($keys['oldmodulus'] ?? '', $keys['oldexponent'] ?? '');

        return self::check($expected, $proof, $current)
            || self::check($expected, $oldProof, $current)
            || self::check($expected, $proof, $old);
    }

    private static function check(string $data, string $signature, ?string $pem): bool
    {
        if ($pem === null || $signature === '') {
            return false;
        }
        $raw = base64_decode($signature, true);
        if ($raw === false) {
            return false;
        }

        return openssl_verify($data, $raw, $pem, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Собирает PEM открытого ключа RSA из модуля и экспоненты (base64).
     */
    private static function publicKey(string $modulus, string $exponent): ?string
    {
        $n = base64_decode($modulus, true);
        $e = base64_decode($exponent, true);
        if (!$n || !$e) {
            return null;
        }

        $rsaKey = self::sequence(self::integer($n) . self::integer($e));
        $algorithm = self::sequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00");
        $spki = self::sequence($algorithm . "\x03" . self::length(strlen($rsaKey) + 1) . "\x00" . $rsaKey);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) > 0x7f) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::length(strlen($bytes)) . $bytes;
    }

    private static function sequence(string $content): string
    {
        return "\x30" . self::length(strlen($content)) . $content;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
