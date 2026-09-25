<?php

declare(strict_types=1);

namespace App\Service\Backup\Exception;

/** A well-formed backup that does not fit this account; always raised before any deletion. */
final class BackupDoesNotFitException extends \RuntimeException
{
}
