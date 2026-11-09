<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Backup\Dto\BackupTotals;
use App\Service\Backup\Model\BackupProvenanceModel;
use App\Service\Backup\Support\BackupLineFormat;
use App\Service\Backup\Support\BackupSchema;

/**
 * Encodes one backup record as its NDJSON line, the schema BackupReader parses back. The exporter decides which
 * lines to write and in what order, and reads the header's instant once so every part shares it.
 */
final readonly class BackupLines
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

    public function foundationHeader(BackupProvenanceModel $provenance, int $parts, BackupTotals $totals): string
    {
        return BackupLineFormat::encode([
            ...$this->headerFields($provenance, 0),
            'parts' => $parts,
            'totals' => ['entries' => $totals->entries, 'entryStates' => $totals->entryStates],
        ]);
    }

    public function entryPartHeader(BackupProvenanceModel $provenance, int $part): string
    {
        return BackupLineFormat::encode([
            ...$this->headerFields($provenance, $part),
            'parts' => null,
            'totals' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function headerFields(BackupProvenanceModel $provenance, int $part): array
    {
        return [
            'kind' => BackupSchema::KIND_HEADER,
            'schemaVersion' => BackupSchema::VERSION,
            'createdAt' => BackupLineFormat::date($provenance->createdAt),
            'sourceUrl' => $provenance->sourceUrl,
            'sourceEmail' => $provenance->sourceEmail,
            'backupId' => $provenance->backupId,
            'part' => $part,
        ];
    }

    /**
     * @param array<string, int> $counts keyed by BackupSchema::KIND_*; an absent kind counts zero
     */
    public function footerLine(array $counts): string
    {
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_FOOTER,
            'counts' => array_replace(self::NO_COUNTS, $counts),
        ]);
    }
}
