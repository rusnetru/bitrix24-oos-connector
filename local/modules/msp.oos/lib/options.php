<?php

declare(strict_types=1);

namespace Msp\Oos;

use Bitrix\Main\Config\Option;

/**
 * Настройки модуля (Настройки -> Настройки модулей -> Office Online Server).
 */
final class Options
{
    public const MODULE_ID = 'msp.oos';

    /** Путь WOPI-эндпоинта на портале; на него указывает правило urlrewrite. */
    public const WOPI_FILES_PATH = '/wopi/files/';

    /** Время жизни блокировки по спецификации WOPI — 30 минут. */
    public const LOCK_TTL = 1800;

    public static function get(string $name, string $default = ''): string
    {
        return (string)Option::get(self::MODULE_ID, $name, $default);
    }

    public static function set(string $name, string $value): void
    {
        Option::set(self::MODULE_ID, $name, $value);
    }

    /** Адрес фермы Office Online Server, например https://oos.company.local */
    public static function oosUrl(): string
    {
        return rtrim(self::get('oos_url'), '/');
    }

    /** Адрес портала, по которому его видят OOS и браузеры, например https://portal.company.local */
    public static function portalUrl(): string
    {
        return rtrim(self::get('portal_url'), '/');
    }

    /** Зона из discovery: internal-https, external-https, internal-http, external-http. */
    public static function netZone(): string
    {
        return self::get('net_zone', 'internal-https');
    }

    public static function language(): string
    {
        return self::get('ui_lang', 'ru-RU');
    }

    /** Время жизни токена доступа, секунд. */
    public static function tokenTtl(): int
    {
        return max(600, (int)self::get('token_ttl', '36000'));
    }

    public static function verifyProof(): bool
    {
        return self::get('verify_proof', 'N') === 'Y';
    }

    public static function sslVerify(): bool
    {
        return self::get('ssl_verify', 'Y') === 'Y';
    }

    /** Секрет для подписи токенов; создаётся при установке. */
    public static function secret(): string
    {
        $secret = self::get('secret');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            self::set('secret', $secret);
        }

        return $secret;
    }

    public static function wopiSrc(int $fileId): string
    {
        return self::portalUrl() . self::WOPI_FILES_PATH . $fileId;
    }

    public static function hostUrl(int $fileId, string $mode): string
    {
        return self::portalUrl() . '/bitrix/tools/msp_oos/edit.php?id=' . $fileId . '&action=' . $mode;
    }
}
