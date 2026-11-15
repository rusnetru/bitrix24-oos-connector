<?php

declare(strict_types=1);

namespace Msp\Oos;

use Bitrix\Main\UserTable;

/**
 * WOPI-хост поверх Диска. Обрабатывает запросы OOS:
 *   GET  /wopi/files/{id}            CheckFileInfo
 *   GET  /wopi/files/{id}/contents   GetFile
 *   POST /wopi/files/{id}/contents   PutFile (X-WOPI-Override: PUT)
 *   POST /wopi/files/{id}            LOCK, UNLOCK_AND_RELOCK, GET_LOCK, REFRESH_LOCK, UNLOCK, PUT_USER_INFO
 */
final class WopiServer
{
    private int $fileId = 0;

    public function handle(): void
    {
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (!preg_match('#/wopi/files/(\d+)(/contents)?/?$#', $path, $m)) {
            $this->send(404);

            return;
        }
        $this->fileId = (int)$m[1];
        $isContents = !empty($m[2]);

        $token = (string)($_GET['access_token'] ?? '');
        $claims = Token::parse($token);
        if ($claims === null || $claims['fileId'] !== $this->fileId) {
            $this->send(401);

            return;
        }

        if (Options::verifyProof() && !$this->proofIsValid($token)) {
            Log::warning('proof', 'Неверная подпись запроса OOS: ' . $path, $this->fileId);
            $this->send(500);

            return;
        }

        $file = DiskFile::load($this->fileId);
        if ($file === null) {
            $this->send(404);

            return;
        }

        $userId = $claims['userId'];
        if (!$file->canRead($userId)) {
            $this->send(401);

            return;
        }
        $canWrite = $claims['canWrite'] && $file->canWrite($userId);

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $override = strtoupper(trim((string)($_SERVER['HTTP_X_WOPI_OVERRIDE'] ?? '')));
        $lock = (string)($_SERVER['HTTP_X_WOPI_LOCK'] ?? '');

        if ($isContents) {
            if ($method === 'GET') {
                $this->getFile($file);
            } elseif ($method === 'POST' && $override === 'PUT') {
                $this->putFile($file, $userId, $canWrite, $lock);
            } else {
                $this->send(501);
            }

            return;
        }

        if ($method === 'GET') {
            $this->checkFileInfo($file, $userId, $canWrite, $claims['back']);

            return;
        }
        if ($method !== 'POST') {
            $this->send(501);

            return;
        }

        switch ($override) {
            case 'LOCK':
                if (isset($_SERVER['HTTP_X_WOPI_OLDLOCK'])) {
                    $this->unlockAndRelock($lock, (string)$_SERVER['HTTP_X_WOPI_OLDLOCK'], $canWrite);
                } else {
                    $this->lock($lock, $canWrite);
                }
                break;
            case 'GET_LOCK':
                $this->send(200, ['X-WOPI-Lock' => LockManager::current($this->fileId)]);
                break;
            case 'REFRESH_LOCK':
                $this->refreshLock($lock);
                break;
            case 'UNLOCK':
                $this->unlock($lock);
                break;
            case 'PUT_USER_INFO':
                $this->send(200);
                break;
            default:
                // PUT_RELATIVE, RENAME_FILE, DELETE и прочее не поддерживаем (см. CheckFileInfo).
                $this->send(501);
        }
    }

    private function checkFileInfo(DiskFile $file, int $userId, bool $canWrite, string $back): void
    {
        $portal = Options::portalUrl();
        $info = [
            'BaseFileName' => $file->name(),
            'OwnerId' => (string)$file->ownerId(),
            'Size' => $file->size(),
            'UserId' => (string)$userId,
            'UserFriendlyName' => $this->userName($userId),
            'Version' => $file->version(),
            'LastModifiedTime' => gmdate('Y-m-d\TH:i:s\Z', $file->updatedAt()),
            'UserCanWrite' => $canWrite,
            'ReadOnly' => !$canWrite,
            'SupportsUpdate' => true,
            'SupportsLocks' => true,
            'SupportsGetLock' => true,
            'SupportsExtendedLockLength' => true,
            'UserCanNotWriteRelative' => true,
            'SupportsRename' => false,
            'UserCanRename' => false,
            'BreadcrumbBrandName' => 'Битрикс24',
            'BreadcrumbBrandUrl' => $portal . '/',
            'BreadcrumbDocName' => $file->name(),
            'CloseUrl' => $portal . ($back !== '' ? $back : '/docs/'),
            'HostEditUrl' => Options::hostUrl($file->id(), 'edit'),
            'HostViewUrl' => Options::hostUrl($file->id(), 'view'),
        ];

        $this->send(
            200,
            [],
            json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'application/json; charset=utf-8'
        );
    }

    private function getFile(DiskFile $file): void
    {
        $path = $file->contentPath();
        if ($path === null) {
            Log::error('get', 'Содержимое файла недоступно', $this->fileId);
            $this->send(500);

            return;
        }

        $this->cleanBuffers();
        http_response_code(200);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($path));
        header('X-WOPI-ItemVersion: ' . $file->version());
        readfile($path);
    }

    private function putFile(DiskFile $file, int $userId, bool $canWrite, string $lock): void
    {
        if (!$canWrite) {
            $this->send(401);

            return;
        }

        LockManager::withMutex($this->fileId, function () use ($file, $userId, $lock): void {
            $current = LockManager::current($this->fileId);
            if ($current === '') {
                // Без блокировки разрешена только запись в пустой файл.
                if ($file->size() > 0) {
                    $this->conflict('', 'File is not locked');

                    return;
                }
            } elseif ($current !== $lock) {
                $this->conflict($current, 'Lock mismatch');

                return;
            }

            $tmp = $this->bodyToTempFile($file->extension());
            if ($tmp === null) {
                $this->send(500);

                return;
            }

            try {
                $saved = $file->saveVersion($tmp, $userId);
            } finally {
                @unlink($tmp);
            }

            if (!$saved) {
                $this->send(500);

                return;
            }
            $this->send(200, ['X-WOPI-ItemVersion' => $file->version()]);
        });
    }

    private function lock(string $lock, bool $canWrite): void
    {
        if (!$canWrite) {
            $this->send(401);

            return;
        }
        if ($lock === '') {
            $this->send(400);

            return;
        }

        LockManager::withMutex($this->fileId, function () use ($lock): void {
            $current = LockManager::current($this->fileId);
            if ($current === '' || $current === $lock) {
                LockManager::set($this->fileId, $lock);
                $this->send(200);

                return;
            }
            $this->conflict($current, 'Locked by another session');
        });
    }

    private function unlockAndRelock(string $lock, string $oldLock, bool $canWrite): void
    {
        if (!$canWrite) {
            $this->send(401);

            return;
        }

        LockManager::withMutex($this->fileId, function () use ($lock, $oldLock): void {
            $current = LockManager::current($this->fileId);
            if ($current !== '' && $current === $oldLock) {
                LockManager::set($this->fileId, $lock);
                $this->send(200);

                return;
            }
            $this->conflict($current, 'Old lock mismatch');
        });
    }

    private function refreshLock(string $lock): void
    {
        LockManager::withMutex($this->fileId, function () use ($lock): void {
            $current = LockManager::current($this->fileId);
            if ($current !== '' && $current === $lock) {
                LockManager::set($this->fileId, $lock);
                $this->send(200);

                return;
            }
            $this->conflict($current, 'Lock mismatch');
        });
    }

    private function unlock(string $lock): void
    {
        LockManager::withMutex($this->fileId, function () use ($lock): void {
            $current = LockManager::current($this->fileId);
            if ($current !== '' && $current === $lock) {
                LockManager::clear($this->fileId);
                $this->send(200);

                return;
            }
            $this->conflict($current, 'Lock mismatch');
        });
    }

    private function proofIsValid(string $token): bool
    {
        $url = Options::portalUrl() . (string)($_SERVER['REQUEST_URI'] ?? '');
        $timestamp = (string)($_SERVER['HTTP_X_WOPI_TIMESTAMP'] ?? '');
        $proof = (string)($_SERVER['HTTP_X_WOPI_PROOF'] ?? '');
        $oldProof = (string)($_SERVER['HTTP_X_WOPI_PROOFOLD'] ?? '');

        foreach ([false, true] as $refresh) {
            $discovery = Discovery::get($refresh);
            if ($discovery !== null && !empty($discovery['proof'])
                && ProofKey::verify($token, $url, $timestamp, $proof, $oldProof, $discovery['proof'])) {
                return true;
            }
        }

        return false;
    }

    private function bodyToTempFile(string $extension): ?string
    {
        $dir = \CTempFile::GetDirectoryName(1, 'msp_oos');
        CheckDirPath($dir);
        $path = $dir . bin2hex(random_bytes(8)) . ($extension !== '' ? '.' . $extension : '');

        $in = fopen('php://input', 'rb');
        $out = fopen($path, 'wb');
        if ($in === false || $out === false) {
            Log::error('save', 'Не удалось записать тело запроса во временный файл', $this->fileId);

            return null;
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        return $path;
    }

    private function userName(int $userId): string
    {
        $user = UserTable::getRow([
            'select' => ['NAME', 'LAST_NAME', 'LOGIN'],
            'filter' => ['=ID' => $userId],
        ]);
        if ($user === null) {
            return 'Пользователь ' . $userId;
        }
        $name = trim($user['NAME'] . ' ' . $user['LAST_NAME']);

        return $name !== '' ? $name : (string)$user['LOGIN'];
    }

    private function conflict(string $currentLock, string $reason): void
    {
        $this->send(409, ['X-WOPI-Lock' => $currentLock, 'X-WOPI-LockFailureReason' => $reason]);
    }

    private function send(int $status, array $headers = [], ?string $body = null, string $contentType = ''): void
    {
        $this->cleanBuffers();
        http_response_code($status);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($body !== null) {
            header('Content-Type: ' . $contentType);
            header('Content-Length: ' . strlen($body));
            echo $body;
        }
    }

    private function cleanBuffers(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}
