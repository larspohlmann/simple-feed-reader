<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Backup\Dto\BackupHeader;
use App\Service\Backup\Exception\InvalidBackupException;

/**
 * Enforces one part's per-line limits and its kind grammar. A fresh instance
 * is created per read() call once the header is known, so it never leaks
 * state between backups.
 */
final class BackupPartGuard
{
    private const array FOUNDATION_KINDS = [
        BackupSchema::KIND_ACCOUNT,
        BackupSchema::KIND_TAG,
        BackupSchema::KIND_SAVED_SEARCH,
        BackupSchema::KIND_FEED,
        BackupSchema::KIND_SUBSCRIPTION,
    ];

    private const array ENTRY_PART_KINDS = [
        BackupSchema::KIND_ENTRY,
        BackupSchema::KIND_ENTRY_STATE,
    ];

    private int $inflatedBytes = 0;
    private int $entryLines = 0;

    public function __construct(private readonly BackupHeader $header)
    {
    }

    public function seeLine(string $line): void
    {
        $this->inflatedBytes += \strlen($line) + 1;
        if ($this->inflatedBytes > BackupReader::MAX_INFLATED_BYTES) {
            throw new InvalidBackupException(sprintf(
                'Part %d inflates past %d bytes.',
                $this->header->part,
                BackupReader::MAX_INFLATED_BYTES,
            ));
        }
    }

    public function seeKind(string $kind, int $lineNumber): void
    {
        $allowedKinds = $this->header->isFoundation() ? self::FOUNDATION_KINDS : self::ENTRY_PART_KINDS;
        if (!\in_array($kind, $allowedKinds, true)) {
            throw new InvalidBackupException(sprintf(
                'Line %d of kind "%s" does not belong in part %d.',
                $lineNumber,
                $kind,
                $this->header->part,
            ));
        }

        if (BackupSchema::KIND_ENTRY === $kind) {
            $this->assertEntryCeilingHolds();
        }
    }

    private function assertEntryCeilingHolds(): void
    {
        ++$this->entryLines;
        if ($this->entryLines > BackupReader::MAX_ENTRIES_PER_PART) {
            throw new InvalidBackupException(sprintf(
                'Part %d has more than %d entries.',
                $this->header->part,
                BackupReader::MAX_ENTRIES_PER_PART,
            ));
        }
    }
}
