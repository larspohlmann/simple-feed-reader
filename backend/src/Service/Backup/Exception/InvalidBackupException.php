<?php

declare(strict_types=1);

namespace App\Service\Backup\Exception;

use App\Exception\ApiException;

/**
 * The uploaded bytes do not parse as a backup: not gzip, not NDJSON, a
 * missing or misordered line, or a schema version this instance cannot read.
 * Always raised BEFORE any deletion — a file that cannot be fully read must
 * never cost the account anything.
 */
final class InvalidBackupException extends ApiException
{
    public function __construct(string $detail)
    {
        parent::__construct('invalid_backup', 422, 'Invalid backup file', $detail);
    }
}
