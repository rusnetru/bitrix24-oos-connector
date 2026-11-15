<?php

declare(strict_types=1);

namespace Msp\Oos;

/**
 * Страница-хост: выдаёт токен и открывает редактор OOS во фрейме на всю страницу.
 */
final class HostPage
{
    public static function render(int $fileId, string $mode, int $userId, string $back): string
    {
        if (Options::oosUrl() === '' || Options::portalUrl() === '') {
            return self::error('Модуль не настроен: укажите адреса OOS и портала в настройках модуля.', $back);
        }

        $file = DiskFile::load($fileId);
        if ($file === null) {
            return self::error('Файл не найден.', $back);
        }
        if (!$file->canRead($userId)) {
            return self::error('Нет доступа к файлу.', $back);
        }

        $discovery = Discovery::get();
        if ($discovery === null) {
            return self::error('Office Online Server недоступен. Подробности в журнале событий портала.', $back);
        }

        $ext = $file->extension();
        $actions = $discovery['actions'][$ext] ?? [];
        $canWrite = $file->canWrite($userId);

        $action = null;
        if ($mode === 'edit' && $canWrite && isset($actions['edit'])) {
            $action = 'edit';
        } elseif (isset($actions['view'])) {
            $action = 'view';
        }
        if ($action === null) {
            return self::error('Формат .' . $ext . ' не поддерживается Office Online Server.', $back);
        }

        $token = Token::issue($userId, $fileId, $canWrite, $back);
        $url = Discovery::actionUrl($actions[$action], Options::wopiSrc($fileId), Options::language());
        $icon = $discovery['icons'][$ext] ?? '';

        return self::page($file->name(), $url, $token['token'], $token['ttl'], $icon);
    }

    /**
     * Оставляет только относительный путь этого же портала — чтобы CloseUrl не увёл на чужой сайт.
     */
    public static function safeBack(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return '';
        }

        if (isset($parts['host'])) {
            $allowed = [
                (string)parse_url(Options::portalUrl(), PHP_URL_HOST),
                (string)($_SERVER['HTTP_HOST'] ?? ''),
            ];
            if (!in_array($parts['host'], $allowed, true)) {
                return '';
            }
        }

        $path = (string)($parts['path'] ?? '');
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '/bitrix/tools/msp_oos/')) {
            return '';
        }

        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private static function page(string $title, string $actionUrl, string $token, int $ttl, string $icon): string
    {
        $title = htmlspecialcharsbx($title);
        $actionUrl = htmlspecialcharsbx($actionUrl);
        $token = htmlspecialcharsbx($token);
        $iconTag = $icon !== '' ? '<link rel="shortcut icon" href="' . htmlspecialcharsbx($icon) . '">' : '';

        return <<<HTML
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>{$title}</title>
{$iconTag}
<style>
html, body { margin: 0; padding: 0; height: 100%; overflow: hidden; }
#office_frame { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: none; display: block; }
</style>
</head>
<body>
<form id="office_form" name="office_form" target="office_frame" action="{$actionUrl}" method="post">
<input name="access_token" value="{$token}" type="hidden">
<input name="access_token_ttl" value="{$ttl}" type="hidden">
</form>
<span id="frameholder"></span>
<script>
var frame = document.createElement('iframe');
frame.name = 'office_frame';
frame.id = 'office_frame';
frame.title = 'Office';
frame.setAttribute('allowfullscreen', 'true');
frame.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups allow-top-navigation allow-popups-to-escape-sandbox allow-downloads allow-modals');
document.getElementById('frameholder').appendChild(frame);
document.getElementById('office_form').submit();
</script>
</body>
</html>
HTML;
    }

    private static function error(string $message, string $back): string
    {
        $message = htmlspecialcharsbx($message);
        $link = htmlspecialcharsbx($back !== '' ? $back : '/docs/');

        return <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><title>Office Online</title>
<style>body{font:15px/1.5 system-ui,sans-serif;margin:48px;color:#333}</style></head>
<body><p>{$message}</p><p><a href="{$link}">Вернуться</a></p></body></html>
HTML;
    }
}
