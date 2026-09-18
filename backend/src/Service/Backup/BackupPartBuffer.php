<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * Accumulates one entry part's lines until it hits its byte or entry budget,
 * then drains itself into gzip-compressed bytes and resets for the next part.
 * Mutable by design — the per-export walk owns one instance and fills it as
 * it streams entries, which is exactly why it is not `readonly`.
 */
final class BackupPartBuffer
{
    public const int MAX_BYTES = 8_388_608;
    public const int MAX_ENTRIES = 2000;

    /** @var list<string> */
    private array $entryLines = [];

    /** @var list<string> */
    private array $entryStateLines = [];

    private int $bytes = 0;

    public function add(string $entryLine, ?string $entryStateLine): void
    {
        $this->entryLines[] = $entryLine;
        $this->bytes += \strlen($entryLine);

        if (null !== $entryStateLine) {
            $this->entryStateLines[] = $entryStateLine;
            $this->bytes += \strlen($entryStateLine);
        }
    }

    public function isFull(): bool
    {
        return $this->entryCount() >= self::MAX_ENTRIES || $this->bytes >= self::MAX_BYTES;
    }

    public function isEmpty(): bool
    {
        return [] === $this->entryLines;
    }

    public function entryCount(): int
    {
        return \count($this->entryLines);
    }

    public function entryStateCount(): int
    {
        return \count($this->entryStateLines);
    }

    public function drain(string $headerLine, string $footerLine): string
    {
        $lines = [$headerLine, ...$this->entryLines, ...$this->entryStateLines, $footerLine];
        $gzipBytes = BackupPart::gzip(implode("\n", $lines) . "\n");
        $this->reset();

        return $gzipBytes;
    }

    private function reset(): void
    {
        $this->entryLines = [];
        $this->entryStateLines = [];
        $this->bytes = 0;
    }
}
