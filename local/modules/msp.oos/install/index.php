<?php

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\SiteTable;
use Bitrix\Main\UrlRewriter;

class msp_oos extends CModule
{
    public $MODULE_ID = 'msp.oos';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'Office Online Server для Диска';
    public $MODULE_DESCRIPTION = 'Редактирование документов Диска в Word, Excel и PowerPoint Online (WOPI)';
    public $PARTNER_NAME = 'MSP';
    public $PARTNER_URI = '';

    private const TOOLS_DIR = '/bitrix/tools/msp_oos';
    private const URL_CONDITION = '#^/wopi/files/#';

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
    }

    public function DoInstall()
    {
        global $APPLICATION;

        if (!ModuleManager::isModuleInstalled('disk')) {
            $APPLICATION->ThrowException('Для работы нужен модуль «Диск» (disk).');

            return false;
        }

        ModuleManager::registerModule($this->MODULE_ID);
        Loader::includeModule($this->MODULE_ID);

        $this->InstallDB();
        $this->InstallFiles();
        $this->installUrlRewrite();
        \Msp\Oos\Options::secret();

        return true;
    }

    public function DoUninstall()
    {
        Loader::includeModule($this->MODULE_ID);

        $this->uninstallUrlRewrite();
        $this->UnInstallFiles();
        $this->UnInstallDB();
        ModuleManager::unRegisterModule($this->MODULE_ID);

        return true;
    }

    public function InstallDB()
    {
        $connection = Application::getConnection();
        $table = \Msp\Oos\LockTable::getTableName();
        if (!$connection->isTableExists($table)) {
            \Msp\Oos\LockTable::getEntity()->createDbTable();
        }

        return true;
    }

    public function UnInstallDB()
    {
        $connection = Application::getConnection();
        $table = \Msp\Oos\LockTable::getTableName();
        if ($connection->isTableExists($table)) {
            $connection->dropTable($table);
        }

        return true;
    }

    public function InstallFiles()
    {
        CopyDirFiles(__DIR__ . '/tools', $_SERVER['DOCUMENT_ROOT'] . self::TOOLS_DIR, true, true);

        return true;
    }

    public function UnInstallFiles()
    {
        DeleteDirFilesEx(self::TOOLS_DIR);

        return true;
    }

    private function installUrlRewrite(): void
    {
        foreach ($this->siteIds() as $siteId) {
            $existing = UrlRewriter::getList($siteId, ['CONDITION' => self::URL_CONDITION]);
            if (!empty($existing)) {
                continue;
            }
            UrlRewriter::add($siteId, [
                'CONDITION' => self::URL_CONDITION,
                'RULE' => '',
                'ID' => '',
                'PATH' => self::TOOLS_DIR . '/wopi.php',
                'SORT' => 10,
            ]);
        }
    }

    private function uninstallUrlRewrite(): void
    {
        foreach ($this->siteIds() as $siteId) {
            UrlRewriter::delete($siteId, ['CONDITION' => self::URL_CONDITION]);
        }
    }

    private function siteIds(): array
    {
        $ids = [];
        $result = SiteTable::getList(['select' => ['LID']]);
        while ($row = $result->fetch()) {
            $ids[] = $row['LID'];
        }

        return $ids;
    }
}
