<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

use App\Service\Logging\Loki\Exception\CorruptSpoolFileException;

/**
 * Drains the Loki spool out-of-band. LokiClient::push() is fail-open, so a
 * dead Loki silently drops a batch; the only local failure is a corrupt file,
 * which is deleted so it cannot wedge the queue.
 */
final readonly class LokiSpoolShipper
{
    private const int MAX_FILES_PER_TICK = 100;

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
            try {
                $this->client->push(self::linesIn($file));
                ++$shipped;
            } catch (CorruptSpoolFileException) {
                ++$failed;
            } finally {
                @unlink($file);
            }
        }

        return new LokiSpoolReport($shipped, $failed);
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob($this->spoolDirectory . '/*.json');
        if (false === $files) {
            return [];
        }

        return array_slice($files, 0, self::MAX_FILES_PER_TICK);
    }

    /**
     * @return list<array{ts: string, line: string, labels: array<string, string>}>
     *
     * @throws CorruptSpoolFileException
     */
    private static function linesIn(string $file): array
    {
        // Silenced: a concurrent tick may have shipped the file since glob(), and the error handler would throw.
        $contents = @file_get_contents($file);
        if (false === $contents) {
            throw new CorruptSpoolFileException($file);
        }

        try {
            $lines = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CorruptSpoolFileException($file, $e);
        }
        if (!\is_array($lines)) {
            throw new CorruptSpoolFileException($file);
        }

        /** @var list<array{ts: string, line: string, labels: array<string, string>}> $lines */
        return $lines;
    }
}
