<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Service\Backup\Exception\InvalidBackupException;

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
     * @throws InvalidBackupException
     */
    public function requireCoherent(): self
    {
        if ($this->part < 0) {
            throw new InvalidBackupException(sprintf('Header part %d is negative.', $this->part));
        }

        if ($this->isFoundation()) {
            return $this->requireFoundationPartsAndTotals();
        }

        return $this->requireEntryPartDeclaresNeither();
    }

    private function requireFoundationPartsAndTotals(): self
    {
        if (null === $this->parts || $this->parts < 1 || null === $this->totals) {
            throw new InvalidBackupException('The foundation must declare its parts count and totals.');
        }

        return $this;
    }

    private function requireEntryPartDeclaresNeither(): self
    {
        if (null !== $this->parts || null !== $this->totals) {
            throw new InvalidBackupException(sprintf('Entry part %d must not declare parts or totals.', $this->part));
        }

        return $this;
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
