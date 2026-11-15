<?php

declare(strict_types=1);

namespace Msp\Oos;

use Bitrix\Main\Application;

final class LockManager
{
    /**
     * Выполняет $callback под мьютексом БД по файлу: OOS шлёт параллельные запросы на один файл.
     */
    public static function withMutex(int $fileId, callable $callback): void
    {
        $connection = Application::getConnection();
        $name = 'msp_oos_' . $fileId;
        $locked = false;
        try {
            $locked = (bool)$connection->lock($name, 10);
        } catch (\Throwable $e) {
            Log::warning('lock', 'Мьютекс БД недоступен: ' . $e->getMessage(), $fileId);
        }

        try {
            $callback();
        } finally {
            if ($locked) {
                $connection->unlock($name);
            }
        }
    }

    /** Текущая блокировка или пустая строка, если её нет или она истекла. */
    public static function current(int $fileId): string
    {
        $row = LockTable::getRow([
            'select' => ['LOCK_VALUE', 'EXPIRES_TS'],
            'filter' => ['=FILE_ID' => $fileId],
        ]);
        if ($row === null || (int)$row['EXPIRES_TS'] < time()) {
            return '';
        }

        return (string)$row['LOCK_VALUE'];
    }

    public static function set(int $fileId, string $lock): void
    {
        $fields = ['LOCK_VALUE' => $lock, 'EXPIRES_TS' => time() + Options::LOCK_TTL];
        $exists = LockTable::getRow(['select' => ['FILE_ID'], 'filter' => ['=FILE_ID' => $fileId]]);
        if ($exists !== null) {
            LockTable::update($fileId, $fields);
        } else {
            LockTable::add(['FILE_ID' => $fileId] + $fields);
        }
    }

    public static function clear(int $fileId): void
    {
        LockTable::delete($fileId);
    }
}
