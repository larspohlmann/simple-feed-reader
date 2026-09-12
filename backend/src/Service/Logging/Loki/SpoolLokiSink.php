<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

/**
 * Writes each flush to its own file so concurrent cgi-fcgi processes never
 * contend. Fail-open: a failed spool write silently drops that batch.
 */
final readonly class SpoolLokiSink implements LokiSink
{
    public function __construct(private string $spoolDirectory)
    {
    }

    public function write(array $lines): void
    {
        if ([] === $lines) {
            return;
        }
        try {
            if (!$this->ensureSpoolDirectoryExists()) {
                return;
            }
            file_put_contents($this->filePath(), json_encode($lines, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
        }
    }

    private function ensureSpoolDirectoryExists(): bool
    {
        if (is_dir($this->spoolDirectory)) {
            return true;
        }

        return @mkdir($this->spoolDirectory, 0770, true) || is_dir($this->spoolDirectory);
    }

    private function filePath(): string
    {
        $micros = (int) (microtime(true) * 1_000_000);

        return sprintf('%s/%d-%s.json', $this->spoolDirectory, $micros, bin2hex(random_bytes(6)));
    }
}
