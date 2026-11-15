<?php
/**
 * Открывает файл Диска в Office Online Server.
 *   /bitrix/tools/msp_oos/edit.php?id=<ID файла Диска>[&action=view|edit][&back=<относительный URL>]
 */
defined('NO_KEEP_STATISTIC') || define('NO_KEEP_STATISTIC', true);
defined('NO_AGENT_CHECK') || define('NO_AGENT_CHECK', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

global $USER;
if (!$USER->IsAuthorized()) {
    LocalRedirect('/auth/?backurl=' . urlencode($_SERVER['REQUEST_URI']));
}

if (!\Bitrix\Main\Loader::includeModule('msp.oos')) {
    ShowError('Модуль msp.oos не установлен');
    die();
}

$fileId = (int)($_GET['id'] ?? 0);
$mode = (($_GET['action'] ?? 'edit') === 'view') ? 'view' : 'edit';
$back = \Msp\Oos\HostPage::safeBack((string)($_GET['back'] ?? ($_SERVER['HTTP_REFERER'] ?? '')));

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/html; charset=utf-8');
echo \Msp\Oos\HostPage::render($fileId, $mode, (int)$USER->GetID(), $back);
die();
