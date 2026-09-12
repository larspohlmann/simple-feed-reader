<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

/**
 * Writes each flush to its own file so concurrent cgi-fcgi processes never
 * contend: no lock, no shared offset. LokiSpoolShipper drains and deletes them
 * out-of-band. Fail-open: a spool write that fails drops that batch, exactly as
 * a failed HTTP push would.
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
