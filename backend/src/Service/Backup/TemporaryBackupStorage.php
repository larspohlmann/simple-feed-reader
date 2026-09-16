<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Backup\Exception\BackupStorageException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class TemporaryBackupStorage
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/backup-restore')]
        private string $fallbackDirectory,
        private LoggerInterface $logger,
        private ?string $primaryDirectory = null,
    ) {
    }

    /**
     * @template T
     * @param resource $uploadStream
     * @param callable(TemporaryBackupFile): T $operation
     * @return T
     */
    public function withFile($uploadStream, callable $operation): mixed
    {
        [$path, $destination] = $this->createFile();

        $this->copy($uploadStream, $destination, $path);

        $file = new TemporaryBackupFile($path);
        $callbackFailure = null;

        try {
            return $operation($file);
        } catch (\Throwable $error) {
            $callbackFailure = $error;

            throw $error;
        } finally {
            $this->delete($file, $callbackFailure);
        }
    }

    /** @return array{string, resource} */
    private function createFile(): array
    {
        $primaryFile = $this->createFileIn($this->primaryDirectory ?? sys_get_temp_dir());

        if ($primaryFile !== null) {
            return $primaryFile;
        }

        if (!@mkdir($this->fallbackDirectory, 0700, true) && !is_dir($this->fallbackDirectory)) {
            throw BackupStorageException::cannotCreate();
        }

        return $this->createFileIn($this->fallbackDirectory) ?? throw BackupStorageException::cannotCreate();
    }

    /** @return array{string, resource}|null */
    private function createFileIn(string $directory): ?array
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                $path = $directory . '/backup-' . bin2hex(random_bytes(12)) . '.gz';
            } catch (\Throwable) {
                return null;
            }

            $destination = @fopen($path, 'x+b');

            if ($destination === false) {
                continue;
            }

            if (@chmod($path, 0600)) {
                return [$path, $destination];
            }

            fclose($destination);
            @unlink($path);
        }

        return null;
    }

    /** @param resource $destination */
    private function copy(mixed $uploadStream, mixed $destination, string $path): void
    {
        try {
            if (!is_resource($uploadStream)) {
                throw new \RuntimeException('The upload stream is not readable.');
            }

            if (stream_copy_to_stream($uploadStream, $destination) === false) {
                throw new \RuntimeException('The upload stream could not be copied.');
            }
        } catch (\Throwable $error) {
            fclose($destination);
            $this->deletePartialFile($path);

            throw BackupStorageException::cannotCopy($error);
        }

        fclose($destination);
    }

    private function delete(TemporaryBackupFile $file, ?\Throwable $callbackFailure): void
    {
        try {
            $file->delete();
        } catch (BackupStorageException $error) {
            if ($callbackFailure === null) {
                throw $error;
            }

            $this->reportCleanupFailure($error);
        }
    }

    private function deletePartialFile(string $path): void
    {
        $file = new TemporaryBackupFile($path);

        try {
            $file->delete();
        } catch (BackupStorageException $error) {
            $this->reportCleanupFailure($error);
        } finally {
            @unlink($path);
        }
    }

    private function reportCleanupFailure(BackupStorageException $error): void
    {
        try {
            $this->logger->critical('The temporary backup upload could not be removed.', ['exception' => $error]);
        } catch (\Throwable) {
        }
    }
}
