<?php

declare(strict_types=1);

namespace App\Tests\Support;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
final class PartiallyFailingUploadStream
{
    public mixed $context = null;

    public static ?string $replacementPath = null;

    private static string $directory;

    private static bool $preventsCleanup;

    private bool $returnedPartialBytes = false;

    public static function configure(string $directory, bool $preventsCleanup): void
    {
        self::$directory = $directory;
        self::$preventsCleanup = $preventsCleanup;
        self::$replacementPath = null;

        if (!in_array('partially-failing-upload', stream_get_wrappers(), true)) {
            stream_wrapper_register('partially-failing-upload', self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        if (!$this->returnedPartialBytes) {
            $this->returnedPartialBytes = true;

            return 'partial bytes';
        }

        if (self::$preventsCleanup) {
            $this->replaceFileWithDirectory();
        }

        throw new \RuntimeException('The upload stream failed.');
    }

    public function stream_eof(): bool
    {
        return false;
    }

    /** @return array<never, never> */
    public function stream_stat(): array
    {
        return [];
    }

    private function replaceFileWithDirectory(): void
    {
        $entries = array_diff(scandir(self::$directory) ?: [], ['.', '..']);
        $filename = array_pop($entries);

        if (!is_string($filename)) {
            return;
        }

        $path = self::$directory . '/' . $filename;
        unlink($path);
        mkdir($path);
        self::$replacementPath = $path;
    }
}
// phpcs:enable
