<?php

declare(strict_types=1);

namespace App\Service\Backup\Exception;

use App\Exception\ApiException;

/**
 * The load failed after the wipe: unlike InvalidBackupException (raised by
 * the inspector while the account is still whole), everything here reports
 * from an account already emptied.
 *
 * Two causes: usually the storage layer refuses a value the grammar accepts
 * (a title too long, an integer too wide, a duplicate key) — BackupReader
 * checks types, never widths, and holds no copy of the schema. Rarer is a
 * dangling reference the inspector should already have refused; kept as a
 * backstop since a silent partial load is worse than a loud one.
 *
 * Reported rather than left as an opaque 500: the user must know the account
 * is empty, and spec §8's remedy (re-running the fixed file) applies. The
 * cause is chained for the log only — ApiExceptionListener never puts a
 * previous exception into the problem document, so nothing about the schema
 * reaches the client.
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
