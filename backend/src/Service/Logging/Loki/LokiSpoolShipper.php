<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

/**
 * Drains the Loki spool out-of-band from the machine-facing maintenance tick.
 * LokiClient::push() is fail-open, so a dead Loki silently drops a batch —
 * logging was always best-effort, never durable. The only local failure is a
 * corrupt file, which is deleted so it cannot wedge the queue.
 */
final readonly class LokiSpoolShipper
{
    public function __construct(
        private LokiClient $client,
        private string $spoolDirectory,
    ) {
    }

    public function ship(): LokiSpoolReport
    {
        $shipped = 0;
        $failed = 0;
        foreach ($this->files() as $file) {
            if ($this->shipFile($file)) {
                ++$shipped;
            } else {
                ++$failed;
            }
            @unlink($file);
        }

        return new LokiSpoolReport($shipped, $failed);
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob($this->spoolDirectory . '/*.json');

        return false === $files ? [] : $files;
    }

    private function shipFile(string $file): bool
    {
        try {
            $lines = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($lines)) {
                return false;
            }
            /** @var list<array{ts: string, line: string, labels: array<string, string>}> $lines */
            $this->client->push($lines);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
