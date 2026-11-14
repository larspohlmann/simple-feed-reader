<?php

declare(strict_types=1);

namespace App\Service\Operations\Backup\Dto;

use App\Entity\EntryState;
use App\Service\Operations\Backup\Support\BackupLineFormat;
use App\Service\Operations\Backup\Support\BackupSchema;
use App\Service\Operations\Backup\Support\LineField;

/**
 * The account's per-entry state: read/favourite/kept/viewed flags and their
 * timestamps, matched to a restored entry by (feed, guidHash) — never by
 * guid, since hashing it outside the Entry constructor is forbidden.
 */
final readonly class EntryStateLine
{
    public function __construct(
        public string $feedUrl,
        public string $guidHash,
        public bool $isHidden,
        public bool $isFavorite,
        public bool $isKept,
        public ?\DateTimeImmutable $hiddenAt,
        public bool $isViewed,
        public ?\DateTimeImmutable $viewedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function fromLine(array $line): self
    {
        return new self(
            feedUrl: LineField::string($line, 'feedUrl'),
            guidHash: LineField::string($line, 'guidHash'),
            isHidden: LineField::bool($line, 'isHidden'),
            isFavorite: LineField::bool($line, 'isFavorite'),
            isKept: LineField::bool($line, 'isKept'),
            hiddenAt: LineField::dateOrNull($line, 'hiddenAt'),
            isViewed: LineField::bool($line, 'isViewed'),
            viewedAt: LineField::dateOrNull($line, 'viewedAt'),
        );
    }

    public static function of(EntryState $state, string $feedUrl): self
    {
        return new self(
            feedUrl: $feedUrl,
            guidHash: $state->getEntry()->getGuidHash(),
            isHidden: $state->isHidden(),
            isFavorite: $state->isFavorite(),
            isKept: $state->isKept(),
            hiddenAt: $state->getHiddenAt(),
            isViewed: $state->isViewed(),
            viewedAt: $state->getViewedAt(),
        );
    }

    public function toBackupLine(): string
    {
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_ENTRY_STATE,
            'feedUrl' => $this->feedUrl,
            'guidHash' => $this->guidHash,
            'isHidden' => $this->isHidden,
            'isFavorite' => $this->isFavorite,
            'isKept' => $this->isKept,
            'hiddenAt' => BackupLineFormat::dateOrNull($this->hiddenAt),
            'isViewed' => $this->isViewed,
            'viewedAt' => BackupLineFormat::dateOrNull($this->viewedAt),
        ]);
    }
}
