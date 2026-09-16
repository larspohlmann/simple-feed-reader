<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\Exception\BackupStorageException;
use App\Service\Backup\TemporaryBackupFile;
use App\Service\Backup\TemporaryBackupStorage;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\PartiallyFailingUploadStream;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

final class TemporaryBackupStorageTest extends TestCase
{
    private string $directory;

    private string $fallbackDirectory;

    private string $primaryDirectory;

    /** @var list<resource> */
    private array $sourceStreams = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/backup-storage-' . bin2hex(random_bytes(8));
        $this->primaryDirectory = $this->directory . '/primary';
        $this->fallbackDirectory = $this->directory . '/fallback';

        mkdir($this->primaryDirectory, 0700, true);
        PartiallyFailingUploadStream::configure($this->primaryDirectory, false);
    }

    protected function tearDown(): void
    {
        foreach ($this->sourceStreams as $sourceStream) {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }
        }

        $this->remove($this->directory);
    }

    public function testCopiesExactBytesIntoThePrimaryDirectory(): void
    {
        $result = $this->storage()->withFile(
            $this->source("gzip-bytes\x00\xff"),
            function (TemporaryBackupFile $file): string {
                self::assertSame(1, $this->entryCount($this->primaryDirectory));
                self::assertSame(0, $this->entryCount($this->fallbackDirectory));
                $stream = $file->open();
                try {
                    return (string) stream_get_contents($stream);
                } finally {
                    fclose($stream);
                }
            },
        );

        self::assertSame("gzip-bytes\x00\xff", $result);
        self::assertSame(0, $this->entryCount($this->primaryDirectory));
    }

    public function testFallsBackWhenThePrimaryPathCannotContainAFile(): void
    {
        $this->replaceDirectoryWithFile($this->primaryDirectory);

        $this->storage()->withFile($this->source('bytes'), function (): void {
            self::assertSame(1, $this->entryCount($this->fallbackDirectory));
            self::assertSame(0700, fileperms($this->fallbackDirectory) & 0777);
        });

        self::assertSame(0, $this->entryCount($this->fallbackDirectory));
    }

    public function testThrowsWhenNeitherDirectoryCanContainAFile(): void
    {
        $this->replaceDirectoryWithFile($this->primaryDirectory);
        $this->replaceDirectoryWithFile($this->fallbackDirectory);

        $this->expectException(BackupStorageException::class);
        $this->storage()->withFile($this->source('bytes'), static fn (): null => null);
    }

    public function testDeletesThePartialFileWhenCopyFails(): void
    {
        $failure = null;

        try {
            $this->storage()->withFile($this->partiallyFailingSource(), static fn (): null => null);
        } catch (BackupStorageException $caught) {
            $failure = $caught;
        }

        self::assertInstanceOf(BackupStorageException::class, $failure);
        self::assertSame(0, $this->entryCount($this->primaryDirectory));
    }

    public function testPreservesTheCopyFailureWhenPartialCleanupFails(): void
    {
        PartiallyFailingUploadStream::configure($this->primaryDirectory, true);
        $logger = new RecordingLogger();
        $failure = null;

        try {
            $this->storage($logger)->withFile($this->partiallyFailingSource(), static fn (): null => null);
        } catch (BackupStorageException $caught) {
            $failure = $caught;
        } finally {
            $path = PartiallyFailingUploadStream::$replacementPath;
            if ($path !== null && is_dir($path)) {
                rmdir($path);
            }
        }

        self::assertInstanceOf(BackupStorageException::class, $failure);
        self::assertCount(1, $logger->records);
        self::assertSame('critical', $logger->records[0]['level']);
        self::assertInstanceOf(BackupStorageException::class, $logger->records[0]['context']['exception']);
    }

    public function testDeletesTheFileWhenTheCallbackThrows(): void
    {
        $failure = new \RuntimeException('operation failed');
        $file = null;
        $caughtFailure = null;

        try {
            $this->storage()->withFile(
                $this->source('bytes'),
                static function (TemporaryBackupFile $temporaryBackupFile) use ($failure, &$file): void {
                    $file = $temporaryBackupFile;

                    throw $failure;
                },
            );
        } catch (\RuntimeException $caught) {
            $caughtFailure = $caught;
        }

        self::assertSame($failure, $caughtFailure);
        self::assertSame(0, $this->entryCount($this->primaryDirectory));
        $file = null;
    }

    public function testTwoHandlesStartAtByteZero(): void
    {
        $this->storage()->withFile($this->source('same bytes'), static function (TemporaryBackupFile $file): void {
            $first = $file->open();
            $second = $file->open();
            try {
                self::assertSame('same bytes', stream_get_contents($first));
                self::assertSame('same bytes', stream_get_contents($second));
            } finally {
                fclose($first);
                fclose($second);
            }
        });
    }

    public function testCreatesTheTemporaryFileWithOwnerOnlyPermissions(): void
    {
        $this->storage()->withFile($this->source('bytes'), static function (TemporaryBackupFile $file): void {
            $stream = $file->open();
            $metadata = stream_get_meta_data($stream);
            fclose($stream);

            $path = $metadata['uri'] ?? null;
            self::assertIsString($path);
            self::assertSame(0600, fileperms($path) & 0777);
        });
    }

    public function testThrowsWhenTheSourceStreamIsClosed(): void
    {
        $sourceStream = $this->source('bytes');
        fclose($sourceStream);

        $this->expectException(BackupStorageException::class);
        $this->storage()->withFile($sourceStream, static fn (): null => null);
    }

    public function testDeleteIsIdempotent(): void
    {
        $this->storage()->withFile($this->source('bytes'), static function (TemporaryBackupFile $file): void {
            $file->delete();
            $file->delete();
        });

        self::assertSame(0, $this->entryCount($this->primaryDirectory));
    }

    public function testThrowsWhenCleanupFailsAfterTheCallbackSucceeds(): void
    {
        $path = '';

        try {
            $this->expectException(BackupStorageException::class);
            $this->storage()->withFile($this->source('bytes'), function (TemporaryBackupFile $file) use (&$path): void {
                $path = $this->replaceFileWithDirectory($file);
            });
        } finally {
            if (is_dir($path)) {
                rmdir($path);
            }
        }
    }

    public function testPreservesTheCallbackFailureWhenCleanupFails(): void
    {
        $logger = new RecordingLogger();
        $failure = new \RuntimeException('operation failed');
        $path = '';
        $caughtFailure = null;

        try {
            $this->storage($logger)->withFile(
                $this->source('bytes'),
                function (TemporaryBackupFile $file) use (&$path, $failure): void {
                    $path = $this->replaceFileWithDirectory($file);
                    throw $failure;
                },
            );
        } catch (\RuntimeException $caught) {
            $caughtFailure = $caught;
        } finally {
            if (is_dir($path)) {
                rmdir($path);
            }
        }

        self::assertSame($failure, $caughtFailure);
        self::assertCount(1, $logger->records);
        self::assertSame('critical', $logger->records[0]['level']);
        self::assertInstanceOf(BackupStorageException::class, $logger->records[0]['context']['exception']);
    }

    public function testPreservesTheCallbackFailureWhenCleanupLoggingFails(): void
    {
        $failure = new \LogicException('operation failed');
        $path = '';
        $caughtFailure = null;
        $logger = new class extends AbstractLogger {
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('logger failed');
            }
        };

        try {
            $this->storage($logger)->withFile(
                $this->source('bytes'),
                function (TemporaryBackupFile $file) use (&$path, $failure): void {
                    $path = $this->replaceFileWithDirectory($file);
                    throw $failure;
                },
            );
        } catch (\LogicException $caught) {
            $caughtFailure = $caught;
        } finally {
            if (is_dir($path)) {
                rmdir($path);
            }
        }

        self::assertSame($failure, $caughtFailure);
    }

    /** @return resource */
    private function source(string $contents): mixed
    {
        $sourceStream = fopen('php://temp', 'w+b');
        self::assertIsResource($sourceStream);
        fwrite($sourceStream, $contents);
        rewind($sourceStream);
        $this->sourceStreams[] = $sourceStream;

        return $sourceStream;
    }

    /** @return resource */
    private function partiallyFailingSource(): mixed
    {
        $sourceStream = fopen('partially-failing-upload://source', 'rb');
        self::assertIsResource($sourceStream);
        $this->sourceStreams[] = $sourceStream;

        return $sourceStream;
    }

    private function storage(?LoggerInterface $logger = null): TemporaryBackupStorage
    {
        return new TemporaryBackupStorage(
            $this->fallbackDirectory,
            $logger ?? new RecordingLogger(),
            $this->primaryDirectory,
        );
    }

    private function replaceDirectoryWithFile(string $directory): void
    {
        if (is_dir($directory)) {
            rmdir($directory);
        }

        file_put_contents($directory, 'not a directory');
    }

    private function replaceFileWithDirectory(TemporaryBackupFile $file): string
    {
        $stream = $file->open();
        $metadata = stream_get_meta_data($stream);
        fclose($stream);

        $path = $metadata['uri'] ?? null;
        self::assertIsString($path);
        unlink($path);
        mkdir($path);

        return $path;
    }

    private function entryCount(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        return count(array_diff(scandir($directory) ?: [], ['.', '..']));
    }

    private function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}
