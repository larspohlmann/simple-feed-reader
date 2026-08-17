<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

/**
 * The backup file's first line: format version and provenance, so a restore
 * can refuse a file it does not understand before touching any data.
 */
final readonly class BackupHeader
{
    public function __construct(
        public int $schemaVersion,
        public \DateTimeImmutable $createdAt,
        public ?string $sourceUrl,
        public ?string $sourceEmail,
    ) {
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
        );
    }
}
