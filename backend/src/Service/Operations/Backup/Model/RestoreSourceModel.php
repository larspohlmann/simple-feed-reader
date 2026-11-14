<?php

declare(strict_types=1);

namespace App\Service\Backup\Model;

/** The backup a restore reads: which export, how many parts, when and where it was taken. */
final readonly class RestoreSourceModel
{
    public function __construct(
        public string $backupId,
        public ?int $parts,
        public \DateTimeImmutable $createdAt,
        public ?string $sourceUrl,
        public ?string $sourceEmail,
    ) {
    }
}
