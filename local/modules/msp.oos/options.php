<?php
/**
 * Настройки модуля: /bitrix/admin/settings.php?mid=msp.oos
 */

use Bitrix\Main\Loader;
use Msp\Oos\Discovery;
use Msp\Oos\Options;

defined('B_PROLOG_INCLUDED') && B_PROLOG_INCLUDED === true || die();

global $USER, $APPLICATION;
if (!$USER->IsAdmin() || !Loader::includeModule('msp.oos')) {
    return;
}

$textFields = [
    'oos_url' => ['Адрес Office Online Server', 'https://oos.company.local'],
    'portal_url' => ['Адрес портала (как его видят OOS и браузеры)', 'https://portal.company.local'],
    'net_zone' => ['Зона discovery', 'internal-https'],
    'ui_lang' => ['Язык интерфейса редактора', 'ru-RU'],
    'token_ttl' => ['Время жизни токена, секунд', '36000'],
];
$checkboxFields = [
    'ssl_verify' => ['Проверять SSL-сертификат OOS', 'Y'],
    'verify_proof' => ['Проверять подпись запросов OOS (proof keys)', 'N'],
];

$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    foreach ($textFields as $name => $field) {
        Options::set($name, trim((string)($_POST[$name] ?? '')));
    }
    foreach ($checkboxFields as $name => $field) {
        Options::set($name, ($_POST[$name] ?? 'N') === 'Y' ? 'Y' : 'N');
    }

    $discovery = Discovery::get(true);
    if ($discovery === null) {
        $message = new CAdminMessage([
            'TYPE' => 'ERROR',
            'MESSAGE' => 'Настройки сохранены, но discovery от OOS не получен. Подробности — в журнале событий.',
        ]);
    } else {
        $editable = [];
        foreach ($discovery['actions'] as $ext => $actions) {
            if (isset($actions['edit'])) {
                $editable[] = $ext;
            }
        }
        $message = new CAdminMessage([
            'TYPE' => 'OK',
            'MESSAGE' => 'Настройки сохранены, OOS отвечает. Зона: ' . $discovery['zone']
                . '. Редактирование: ' . implode(', ', $editable)
                . '. Подпись запросов: ' . (empty($discovery['proof']['modulus']) ? 'ключа нет' : 'ключ получен'),
        ]);
    }
}

if ($message) {
    echo $message->Show();
}

$action = $APPLICATION->GetCurPage() . '?mid=' . urlencode('msp.oos') . '&lang=' . LANGUAGE_ID;
?>
<form method="post" action="<?= htmlspecialcharsbx($action) ?>">
    <?= bitrix_sessid_post() ?>
    <table class="adm-detail-content-table edit-table">
        <?php foreach ($textFields as $name => [$label, $default]): ?>
            <tr>
                <td class="adm-detail-content-cell-l" width="40%"><?= htmlspecialcharsbx($label) ?>:</td>
                <td class="adm-detail-content-cell-r">
                    <input type="text" size="50" name="<?= $name ?>"
                           value="<?= htmlspecialcharsbx(Options::get($name, $name === 'oos_url' || $name === 'portal_url' ? '' : $default)) ?>"
                           placeholder="<?= htmlspecialcharsbx($default) ?>">
                </td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($checkboxFields as $name => [$label, $default]): ?>
            <tr>
                <td class="adm-detail-content-cell-l"><?= htmlspecialcharsbx($label) ?>:</td>
                <td class="adm-detail-content-cell-r">
                    <input type="checkbox" name="<?= $name ?>" value="Y"
                        <?= Options::get($name, $default) === 'Y' ? 'checked' : '' ?>>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <br>
    <input type="submit" class="adm-btn-save" value="Сохранить и проверить OOS">
</form>
