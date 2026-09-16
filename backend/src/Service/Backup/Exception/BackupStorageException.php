<?php

declare(strict_types=1);

namespace App\Service\Backup\Exception;

final class BackupStorageException extends \RuntimeException
{
    public static function cannotCreate(?\Throwable $previous = null): self
    {
        return new self('The backup upload cannot be stored temporarily.', 0, $previous);
    }

    public static function cannotCopy(\Throwable $previous): self
    {
        return new self('The backup upload cannot be written to temporary storage.', 0, $previous);
    }

    public static function cannotOpen(?\Throwable $previous = null): self
    {
        return new self('The stored backup upload cannot be read.', 0, $previous);
    }

    public static function cannotDelete(): self
    {
        return new self('The stored backup upload cannot be removed.');
    }
}
