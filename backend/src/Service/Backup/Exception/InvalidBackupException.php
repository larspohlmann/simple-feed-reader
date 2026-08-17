<?php

declare(strict_types=1);

namespace App\Service\Backup\Exception;

use App\Exception\ApiException;

/**
 * The uploaded bytes are not an acceptable backup: not gzip, not readable as
 * gzip, not NDJSON, a missing or misordered line, a schema version this
 * instance cannot read, a repeated tag name, or a reference — a
 * subscription's feed or tag, an entry's or a state's feed — the same file
 * never declares.
 *
 * Every one of those is decided by BackupInspector in pass 1, so this is
 * always raised BEFORE any deletion: a file that cannot be fully accepted
 * must never cost the account anything. The load's own checks for the same
 * conditions are a backstop and raise BackupLoadFailedException instead,
 * because reaching them means the account is already empty.
 */
final class InvalidBackupException extends ApiException
{
    public function __construct(string $detail)
    {
        parent::__construct('invalid_backup', 422, 'Invalid backup file', $detail);
    }
}
