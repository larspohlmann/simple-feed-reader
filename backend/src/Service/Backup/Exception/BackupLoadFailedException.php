<?php

declare(strict_types=1);

namespace App\Service\Backup\Exception;

use App\Exception\ApiException;

/**
 * The database rejected a value the backup file carried. Unlike
 * InvalidBackupException this is raised AFTER the wipe, because it is the
 * storage layer — not the grammar — that refuses: a title longer than the
 * column, an integer wider than its type, a duplicate key. BackupReader
 * checks types, never widths, and deliberately holds no copy of the schema.
 *
 * Reported rather than left to become an opaque 500: the account is empty at
 * this point and the user has to know that, and that spec §8's remedy —
 * running the same file again — applies once the file is fixed. The driver's
 * own message is chained for the log only; ApiExceptionListener never puts a
 * previous exception into the problem document, so nothing about the schema
 * reaches the client.
 */
final class BackupLoadFailedException extends ApiException
{
    private const string DETAIL = 'The restore emptied the account and then could not load the file: '
        . 'the database rejected one of its values. The account is now empty. '
        . 'Correct or re-export the backup, then run the restore again.';

    public static function from(\Throwable $cause): self
    {
        return new self($cause);
    }

    private function __construct(\Throwable $cause)
    {
        parent::__construct(
            'backup_load_failed',
            422,
            'The backup could not be loaded',
            self::DETAIL,
            [],
            $cause,
        );
    }
}
