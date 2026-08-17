<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

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
        public bool $isRead,
        public bool $isFavorite,
        public bool $isKept,
        public ?\DateTimeImmutable $readAt,
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
            isRead: LineField::bool($line, 'isRead'),
            isFavorite: LineField::bool($line, 'isFavorite'),
            isKept: LineField::bool($line, 'isKept'),
            readAt: LineField::dateOrNull($line, 'readAt'),
            isViewed: LineField::bool($line, 'isViewed'),
            viewedAt: LineField::dateOrNull($line, 'viewedAt'),
        );
    }
}
