<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * The facts about an export that every one of its parts' headers repeats
 * verbatim, so a restore can tell they belong to the same backup and were
 * taken at the same instant.
 */
final readonly class BackupProvenance
{
    public function __construct(
        public string $backupId,
        public \DateTimeImmutable $createdAt,
        public ?string $sourceUrl,
        public string $sourceEmail,
    ) {
    }
}
