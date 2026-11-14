<?php

declare(strict_types=1);

namespace App\Service\Backup\Exception;

/**
 * A restore load failed after grammar validation: the storage layer refused a value the grammar accepts, or a
 * reference dangled. The cause is chained for the log only; the message is authored and safe to show.
 */
final class BackupLoadFailedException extends \RuntimeException
{
    private const string REMEDY = 'The account is now empty. '
        . 'Correct or re-export the backup, then run the restore again.';

    private const string REJECTED = 'The restore emptied the account and then could not load the file: '
        . 'the database rejected one of its values. ';

    private const string DANGLING = 'The restore emptied the account and then could not load the file: '
        . 'it refers to a row it never declares. ';

    private const string ADDITIVE = 'A backup part could not be loaded: '
        . 'the database rejected one of its values. The account was not emptied; '
        . 'correct or re-export the backup, then continue the restore.';

    public static function from(\Throwable $cause): self
    {
        return new self(self::REJECTED . self::REMEDY, $cause);
    }

    public static function duringEntries(\Throwable $cause): self
    {
        return new self(self::ADDITIVE, $cause);
    }

    /** BackupInspector accepted a reference the load cannot resolve: the two passes disagree about the same bytes. */
    public static function danglingReference(string $reason): self
    {
        return new self(self::DANGLING . self::REMEDY, new \LogicException($reason));
    }

    private function __construct(string $message, \Throwable $cause)
    {
        parent::__construct($message, previous: $cause);
    }
}
