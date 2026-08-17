<?php

declare(strict_types=1);

namespace App\Service\Backup\Exception;

use App\Exception\ApiException;

/**
 * The backup is well-formed but does not fit this account: more
 * subscriptions than the account's limit allows, or more entries than the
 * sanity ceiling permits. Always raised BEFORE any deletion — a refusal here
 * must never cost the account anything.
 */
final class BackupDoesNotFitException extends ApiException
{
    public function __construct(string $detail)
    {
        parent::__construct('backup_does_not_fit', 409, 'The backup does not fit this account', $detail);
    }
}
