<?php

declare(strict_types=1);

namespace App\Service\Backup\Exception;

/** The server could not compress or inflate backup bytes: a server fault, not a bad upload. */
final class BackupCompressionException extends \RuntimeException
{
}
