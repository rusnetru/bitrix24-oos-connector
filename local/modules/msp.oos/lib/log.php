<?php

declare(strict_types=1);

namespace Msp\Oos;

/**
 * Пишет в журнал событий портала (Настройки -> Инструменты -> Журнал событий).
 */
final class Log
{
    public static function error(string $type, string $message, int $fileId = 0): void
    {
        self::add('ERROR', $type, $message, $fileId);
    }

    public static function warning(string $type, string $message, int $fileId = 0): void
    {
        self::add('WARNING', $type, $message, $fileId);
    }

    private static function add(string $severity, string $type, string $message, int $fileId): void
    {
        \CEventLog::Add([
            'SEVERITY' => $severity,
            'AUDIT_TYPE_ID' => 'MSP_OOS_' . strtoupper($type),
            'MODULE_ID' => Options::MODULE_ID,
            'ITEM_ID' => (string)$fileId,
            'DESCRIPTION' => $message,
        ]);
    }
}
