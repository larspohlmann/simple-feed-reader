<?php

declare(strict_types=1);

namespace App\Service\Backup\Exception;

use App\Exception\ApiException;

/**
 * The load failed after the wipe. Unlike InvalidBackupException — which the
 * inspector raises while the account is still whole — everything here is
 * reported from an account that is already empty.
 *
 * Two causes reach it. Usually the storage layer refuses a value the grammar
 * accepts: a title longer than the column, an integer wider than its type, a
 * duplicate key. BackupReader checks types, never widths, and deliberately
 * holds no copy of the schema. The second cause is a dangling reference the
 * inspector should already have refused; it survives as a backstop because a
 * silent partial load would be far worse than a loud one.
 *
 * Reported rather than left to become an opaque 500: the account is empty at
 * this point and the user has to know that, and that spec §8's remedy —
 * running the same file again — applies once the file is fixed. The cause is
 * chained for the log only; ApiExceptionListener never puts a previous
 * exception into the problem document, so nothing about the schema reaches
 * the client.
 */
final class BackupLoadFailedException extends ApiException
{
    private const string REMEDY = 'The account is now empty. '
        . 'Correct or re-export the backup, then run the restore again.';

    private const string REJECTED = 'The restore emptied the account and then could not load the file: '
        . 'the database rejected one of its values. ';

    private const string DANGLING = 'The restore emptied the account and then could not load the file: '
        . 'it refers to a row it never declares. ';

    public static function from(\Throwable $cause): self
    {
        return new self(self::REJECTED . self::REMEDY, $cause);
    }

    /**
     * A reference BackupInspector accepted and the load could not resolve.
     * Reaching this means the two passes disagree about the same bytes, so
     * the reason travels as a chained exception for the log.
     */
    public static function danglingReference(string $reason): self
    {
        return new self(self::DANGLING . self::REMEDY, new \LogicException($reason));
    }

    private function __construct(string $detail, \Throwable $cause)
    {
        parent::__construct(
            'backup_load_failed',
            422,
            'The backup could not be loaded',
            $detail,
            [],
            $cause,
        );
    }
}
