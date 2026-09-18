<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Service\Backup\Exception\InvalidBackupException;

/**
 * The foundation header's declared entry and entry-state counts across every
 * entry part of the backup — a restore's completeness check before it trusts
 * the parts it was actually given.
 */
final readonly class BackupTotals
{
    public function __construct(public int $entries, public int $entryStates)
    {
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function fromHeaderLine(array $line): ?self
    {
        $totals = $line['totals'] ?? null;
        if (null === $totals) {
            return null;
        }
        if (!\is_array($totals)) {
            throw new InvalidBackupException('Field "totals" is not an object.');
        }

        /** @var array<string, mixed> $totals */
        return new self(LineField::int($totals, 'entries'), LineField::int($totals, 'entryStates'));
    }
}
