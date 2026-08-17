<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Service\Backup\Exception\InvalidBackupException;

/**
 * The backup file's closing line: a count per line kind, so the reader can
 * detect a truncated file rather than silently loading a partial account.
 */
final readonly class FooterLine
{
    /**
     * @param array<string, int> $counts kind => count over tag/feed/subscription/entry/entryState
     */
    public function __construct(
        public array $counts,
    ) {
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function fromLine(array $line): self
    {
        $counts = $line['counts'] ?? null;
        if (!\is_array($counts)) {
            throw new InvalidBackupException('Field "counts" is missing or not an object.');
        }

        return new self(counts: self::intCounts($counts));
    }

    /**
     * @param array<mixed, mixed> $counts
     *
     * @return array<string, int>
     */
    private static function intCounts(array $counts): array
    {
        $result = [];
        foreach ($counts as $kind => $count) {
            if (!\is_string($kind) || !\is_int($count)) {
                throw new InvalidBackupException('Field "counts" contains a non-integer entry.');
            }

            $result[$kind] = $count;
        }

        return $result;
    }
}
