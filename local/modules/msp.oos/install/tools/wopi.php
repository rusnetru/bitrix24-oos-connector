<?php
/**
 * Точка входа WOPI. Запросы /wopi/files/... попадают сюда через правило urlrewrite.
 * Авторизация — только по access_token, сессия портала не используется.
 */
defined('NO_KEEP_STATISTIC') || define('NO_KEEP_STATISTIC', true);
defined('NO_AGENT_STATISTIC') || define('NO_AGENT_STATISTIC', true);
defined('NO_AGENT_CHECK') || define('NO_AGENT_CHECK', true);
defined('NOT_CHECK_PERMISSIONS') || define('NOT_CHECK_PERMISSIONS', true);
defined('DisableEventsCheck') || define('DisableEventsCheck', true);
defined('STOP_STATISTICS') || define('STOP_STATISTICS', true);
defined('BX_SECURITY_SESSION_VIRTUAL') || define('BX_SECURITY_SESSION_VIRTUAL', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!\Bitrix\Main\Loader::includeModule('msp.oos')) {
    http_response_code(503);
    die();
}

(new \Msp\Oos\WopiServer())->handle();
die();
