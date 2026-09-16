<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Backup\TemporaryBackupFile;
use App\Service\Backup\TemporaryBackupStorage;
use Psr\Log\NullLogger;

final class TemporaryBackupFixture
{
    /**
     * @template T
     * @param callable(TemporaryBackupFile): T $operation
     * @return T
     */
    public static function withBytes(string $bytes, callable $operation): mixed
    {
        $directory = sys_get_temp_dir() . '/backup-reader-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create the temporary backup fixture directory.');
        }

        $source = null;
        try {
            $source = fopen('php://temp', 'r+b');
            if ($source === false) {
                throw new \RuntimeException('Cannot open the temporary backup fixture source.');
            }

            if (fwrite($source, $bytes) === false || !rewind($source)) {
                throw new \RuntimeException('Cannot prepare the temporary backup fixture source.');
            }

            return (new TemporaryBackupStorage(
                $directory,
                new NullLogger(),
                $directory,
            ))->withFile($source, $operation);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }

            rmdir($directory);
        }
    }
}
