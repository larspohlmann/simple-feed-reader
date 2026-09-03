<?php

declare(strict_types=1);

namespace App\Service\Backup\Exception;

use App\Exception\ApiException;

/**
 * The uploaded bytes are not an acceptable backup: not gzip, not readable as
 * gzip, not NDJSON, a missing or misordered line, an unreadable schema
 * version, a repeated tag name, or a reference — a subscription's feed/tag,
 * an entry's/state's feed — the file never declares.
 *
 * BackupInspector decides all of this in pass 1, so it is always raised
 * BEFORE any deletion: a file that cannot be fully accepted must never cost
 * the account anything. The load's own checks for the same conditions are a
 * backstop, raising BackupLoadFailedException instead, since reaching them
 * means the account is already empty.
 */
final class InvalidBackupException extends ApiException
{
    public function __construct(string $detail)
    {
        parent::__construct('invalid_backup', 422, 'Invalid backup file', $detail);
    }
}
