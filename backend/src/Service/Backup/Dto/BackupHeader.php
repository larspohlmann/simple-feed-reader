<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

/**
 * A backup part's first line: format version, provenance, and where this
 * part sits among its siblings, so a restore can refuse a file it does not
 * understand before touching any data.
 */
final readonly class BackupHeader
{
    public function __construct(
        public int $schemaVersion,
        public \DateTimeImmutable $createdAt,
        public ?string $sourceUrl,
        public ?string $sourceEmail,
        public string $backupId,
        public int $part,
        public ?int $parts,
        public ?BackupTotals $totals,
    ) {
    }

    public function isFoundation(): bool
    {
        return 0 === $this->part;
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function fromLine(array $line): self
    {
        return new self(
            schemaVersion: LineField::int($line, 'schemaVersion'),
            createdAt: LineField::date($line, 'createdAt'),
            sourceUrl: LineField::stringOrNull($line, 'sourceUrl'),
            sourceEmail: LineField::stringOrNull($line, 'sourceEmail'),
            backupId: LineField::string($line, 'backupId'),
            part: LineField::int($line, 'part'),
            parts: LineField::intOrNull($line, 'parts'),
            totals: BackupTotals::fromHeaderLine($line),
        );
    }
}
