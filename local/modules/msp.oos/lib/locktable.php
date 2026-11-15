<?php

declare(strict_types=1);

namespace Msp\Oos;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Блокировки WOPI: одна строка на файл Диска.
 */
final class LockTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_msp_oos_lock';
    }

    public static function getMap(): array
    {
        return [
            new IntegerField('FILE_ID', ['primary' => true]),
            new TextField('LOCK_VALUE', ['required' => true]),
            new IntegerField('EXPIRES_TS', ['required' => true]),
        ];
    }
}
