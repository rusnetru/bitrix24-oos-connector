<?php

declare(strict_types=1);

namespace Msp\Oos;

use Bitrix\Main\Data\Cache;
use Bitrix\Main\Web\HttpClient;

/**
 * Читает https://<oos>/hosting/discovery: адреса редакторов по расширениям и ключи подписи запросов.
 */
final class Discovery
{
    private const CACHE_TTL = 43200;
    private const CACHE_DIR = '/msp.oos/discovery';

    /**
     * @return array{zone: string, actions: array<string, array<string, string>>, icons: array<string, string>, proof: array<string, string>}|null
     */
    public static function get(bool $force = false): ?array
    {
        $oosUrl = Options::oosUrl();
        if ($oosUrl === '') {
            return null;
        }

        $cache = Cache::createInstance();
        $key = md5($oosUrl . '|' . Options::netZone());
        if ($force) {
            $cache->clean($key, self::CACHE_DIR);
        }
        if ($cache->initCache(self::CACHE_TTL, $key, self::CACHE_DIR)) {
            return $cache->getVars();
        }

        $cache->startDataCache();
        $xml = self::fetch($oosUrl . '/hosting/discovery');
        $data = $xml === null ? null : self::parse($xml, Options::netZone());
        if ($data === null) {
            $cache->abortDataCache();

            return null;
        }
        $cache->endDataCache($data);

        return $data;
    }

    /**
     * Подставляет язык, убирает остальные плейсхолдеры вида <name=PLACEHOLDER&> и добавляет WOPISrc.
     */
    public static function actionUrl(string $urlsrc, string $wopiSrc, string $language): string
    {
        $url = (string)preg_replace_callback(
            '/<(\w+)=(\w+)&?>/',
            static function (array $m) use ($language): string {
                if ($m[2] === 'UI_LLCC' || $m[2] === 'DC_LLCC') {
                    return $m[1] . '=' . rawurlencode($language) . '&';
                }

                return '';
            },
            $urlsrc
        );

        if (!str_contains($url, '?')) {
            $url .= '?';
        } elseif (!str_ends_with($url, '?') && !str_ends_with($url, '&')) {
            $url .= '&';
        }

        return $url . 'WOPISrc=' . rawurlencode($wopiSrc);
    }

    private static function fetch(string $url): ?string
    {
        $http = new HttpClient([
            'socketTimeout' => 10,
            'streamTimeout' => 20,
            'disableSslVerification' => !Options::sslVerify(),
        ]);
        $body = $http->get($url);
        if ($body === false || $http->getStatus() !== 200) {
            $errors = implode('; ', $http->getError());
            Log::error('discovery', "Не удалось получить $url: HTTP " . $http->getStatus() . ' ' . $errors);

            return null;
        }

        return (string)$body;
    }

    private static function parse(string $xml, string $zoneName): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);
        if ($doc === false) {
            Log::error('discovery', 'Ответ discovery не является корректным XML');

            return null;
        }

        $zone = null;
        foreach ($doc->{'net-zone'} as $candidate) {
            if ((string)$candidate['name'] === $zoneName) {
                $zone = $candidate;
                break;
            }
        }
        if ($zone === null) {
            foreach ($doc->{'net-zone'} as $candidate) {
                $zone = $candidate;
                Log::warning('discovery', "Зона $zoneName не найдена, используется " . (string)$candidate['name']);
                break;
            }
        }
        if ($zone === null) {
            Log::error('discovery', 'В discovery нет ни одной net-zone');

            return null;
        }

        $actions = [];
        $icons = [];
        foreach ($zone->app as $app) {
            $icon = (string)$app['favIconUrl'];
            foreach ($app->action as $action) {
                $ext = strtolower((string)$action['ext']);
                $name = strtolower((string)$action['name']);
                if ($ext === '' || $name === '') {
                    continue;
                }
                $actions[$ext][$name] = (string)$action['urlsrc'];
                if ($icon !== '') {
                    $icons[$ext] = $icon;
                }
            }
        }

        $proof = [];
        if (isset($doc->{'proof-key'})) {
            $key = $doc->{'proof-key'};
            $proof = [
                'modulus' => (string)$key['modulus'],
                'exponent' => (string)$key['exponent'],
                'oldmodulus' => (string)$key['oldmodulus'],
                'oldexponent' => (string)$key['oldexponent'],
            ];
        }

        return [
            'zone' => (string)$zone['name'],
            'actions' => $actions,
            'icons' => $icons,
            'proof' => $proof,
        ];
    }
}
