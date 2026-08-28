<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

/**
 * One of the account's saved searches.
 */
final readonly class SavedSearchLine
{
    public function __construct(
        public string $term,
        public bool $wholeWord,
        public bool $phrase,
        public int $position,
    ) {
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function fromLine(array $line): self
    {
        return new self(
            term: LineField::string($line, 'term'),
            wholeWord: LineField::bool($line, 'wholeWord'),
            phrase: LineField::bool($line, 'phrase'),
            position: LineField::int($line, 'position'),
        );
    }
}
