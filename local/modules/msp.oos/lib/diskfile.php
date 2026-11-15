<?php

declare(strict_types=1);

namespace Msp\Oos;

use Bitrix\Disk\File;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Main\Loader;

/**
 * Обёртка над файлом Диска: права, содержимое, сохранение новой версии.
 */
final class DiskFile
{
    private function __construct(private File $file)
    {
    }

    public static function load(int $id): ?self
    {
        if ($id <= 0 || !Loader::includeModule('disk')) {
            return null;
        }
        $file = File::loadById($id, ['STORAGE']);

        return $file instanceof File ? new self($file) : null;
    }

    public function id(): int
    {
        return (int)$this->file->getId();
    }

    public function name(): string
    {
        return (string)$this->file->getName();
    }

    public function extension(): string
    {
        return strtolower((string)pathinfo($this->name(), PATHINFO_EXTENSION));
    }

    public function size(): int
    {
        return (int)$this->file->getSize();
    }

    public function ownerId(): int
    {
        return (int)$this->file->getCreatedBy();
    }

    /** Каждая версия Диска — новая запись b_file, поэтому её ID годится как версия WOPI. */
    public function version(): string
    {
        return (string)$this->file->getFileId();
    }

    public function updatedAt(): int
    {
        $time = $this->file->getUpdateTime();

        return $time ? $time->getTimestamp() : time();
    }

    public function canRead(int $userId): bool
    {
        return $this->file->canRead($this->securityContext($userId));
    }

    public function canWrite(int $userId): bool
    {
        return $this->file->canUpdate($this->securityContext($userId));
    }

    /** Локальный путь к содержимому (для облачного хранилища файл будет скачан во временную папку). */
    public function contentPath(): ?string
    {
        $fileArray = \CFile::MakeFileArray($this->file->getFileId());
        $path = is_array($fileArray) ? (string)($fileArray['tmp_name'] ?? '') : '';

        return $path !== '' && is_file($path) ? $path : null;
    }

    public function saveVersion(string $tmpPath, int $userId): bool
    {
        $fileArray = \CFile::MakeFileArray($tmpPath);
        if (!is_array($fileArray)) {
            Log::error('save', 'Не удалось прочитать временный файл', $this->id());

            return false;
        }
        $fileArray['name'] = $this->name();

        $version = $this->file->uploadVersion($fileArray, $userId);
        if (!$version) {
            $errors = array_map(static fn($error) => $error->getMessage(), $this->file->getErrors());
            Log::error('save', 'Disk uploadVersion: ' . implode('; ', $errors), $this->id());

            return false;
        }

        return true;
    }

    private function securityContext(int $userId): SecurityContext
    {
        $storage = $this->file->getStorage();
        if ($storage !== null) {
            return $storage->getSecurityContext($userId);
        }

        return new DiskSecurityContext($userId);
    }
}
