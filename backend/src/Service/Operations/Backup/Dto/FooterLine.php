<?php

declare(strict_types=1);

namespace App\Service\Operations\Backup\Dto;

use App\Service\Operations\Backup\Exception\InvalidBackupException;
use App\Service\Operations\Backup\Support\BackupLineFormat;
use App\Service\Operations\Backup\Support\BackupSchema;

/**
 * The backup file's closing line: a count per line kind, so the reader can
 * detect a truncated file rather than silently loading a partial account.
 */
final readonly class FooterLine
{
    private const array NO_COUNTS = [
        BackupSchema::KIND_TAG => 0,
        BackupSchema::KIND_SAVED_SEARCH => 0,
        BackupSchema::KIND_FEED => 0,
        BackupSchema::KIND_SUBSCRIPTION => 0,
        BackupSchema::KIND_ENTRY => 0,
        BackupSchema::KIND_ENTRY_STATE => 0,
        BackupSchema::KIND_ENTRY_SHARE => 0,
    ];

    /**
     * @param array<string, int> $counts kind => count over tag/feed/subscription/entry/entryState/entryShare
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

    /** @param array<string, int> $counts an absent kind counts zero */
    public static function of(array $counts): self
    {
        return new self(counts: array_replace(self::NO_COUNTS, $counts));
    }

    public function toBackupLine(): string
    {
        return BackupLineFormat::encode(['kind' => BackupSchema::KIND_FOOTER, 'counts' => $this->counts]);
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
