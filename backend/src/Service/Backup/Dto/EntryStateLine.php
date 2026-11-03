<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Service\Backup\Support\LineField;

/**
 * The account's per-entry state: the read, favourite, kept and viewed flags with their timestamps, and the
 * share instant. Matched to a restored entry by (feed, guidHash) — never by guid, since hashing it outside
 * the Entry constructor is forbidden.
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
        public ?\DateTimeImmutable $sharedAt,
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
            sharedAt: LineField::dateOrNull($line, 'sharedAt'),
        );
    }
}
