<?php

declare(strict_types=1);

namespace App\Service\Backup\Exception;

/**
 * The uploaded bytes are not an acceptable backup. BackupInspector raises it in pass 1, before any deletion;
 * the load's own checks for the same conditions raise BackupLoadFailedException instead.
 */
final class InvalidBackupException extends \RuntimeException
{
}
