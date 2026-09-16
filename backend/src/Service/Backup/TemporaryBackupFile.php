<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Backup\Exception\BackupStorageException;

final class TemporaryBackupFile
{
    private bool $deleted = false;

    public function __construct(private readonly string $path)
    {
    }

    public function __destruct()
    {
        if (!$this->deleted) {
            @unlink($this->path);
        }
    }

    /**
     * Each call returns a fresh read-only resource positioned at byte zero.
     *
     * @return resource
     */
    public function open(): mixed
    {
        $stream = @fopen($this->path, 'rb');

        if ($stream === false) {
            throw BackupStorageException::cannotOpen();
        }

        return $stream;
    }

    public function delete(): void
    {
        if ($this->deleted) {
            return;
        }

        if (@unlink($this->path) || !file_exists($this->path)) {
            $this->deleted = true;

            return;
        }

        throw BackupStorageException::cannotDelete();
    }
}
