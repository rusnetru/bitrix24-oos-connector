<?php

declare(strict_types=1);

namespace Msp\Oos;

/**
 * Токен доступа WOPI: подписанный HMAC-SHA256 набор «пользователь, файл, право записи, срок».
 * Хранить на сервере ничего не нужно; права на файл всё равно перепроверяются на каждом запросе.
 */
final class Token
{
    /**
     * @return array{token: string, ttl: int} ttl — момент истечения в миллисекундах (access_token_ttl)
     */
    public static function issue(int $userId, int $fileId, bool $canWrite, string $back): array
    {
        $expires = time() + Options::tokenTtl();
        $payload = [
            'u' => $userId,
            'f' => $fileId,
            'w' => $canWrite ? 1 : 0,
            'e' => $expires,
            'b' => $back,
            'n' => bin2hex(random_bytes(8)),
        ];

        $body = self::encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $signature = self::encode(hash_hmac('sha256', $body, Options::secret(), true));

        return ['token' => $body . '.' . $signature, 'ttl' => $expires * 1000];
    }

    /**
     * @return array{userId: int, fileId: int, canWrite: bool, back: string}|null
     */
    public static function parse(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$body, $signature] = $parts;
        $expected = self::encode(hash_hmac('sha256', $body, Options::secret(), true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $data = json_decode(self::decode($body), true);
        if (!is_array($data) || !isset($data['u'], $data['f'], $data['w'], $data['e'])) {
            return null;
        }
        if ((int)$data['e'] < time()) {
            return null;
        }

        return [
            'userId' => (int)$data['u'],
            'fileId' => (int)$data['f'],
            'canWrite' => (bool)$data['w'],
            'back' => (string)($data['b'] ?? ''),
        ];
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): string
    {
        return (string)base64_decode(strtr($value, '-_', '+/'));
    }
}
