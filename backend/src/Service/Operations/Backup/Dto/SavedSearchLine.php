<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Entity\SavedSearch;
use App\Service\Backup\Support\BackupLineFormat;
use App\Service\Backup\Support\BackupSchema;
use App\Service\Backup\Support\LineField;

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

    public static function of(SavedSearch $savedSearch): self
    {
        return new self(
            term: $savedSearch->getTerm(),
            wholeWord: $savedSearch->isWholeWord(),
            phrase: $savedSearch->isPhrase(),
            position: $savedSearch->getPosition(),
        );
    }

    public function toBackupLine(): string
    {
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_SAVED_SEARCH,
            'term' => $this->term,
            'wholeWord' => $this->wholeWord,
            'phrase' => $this->phrase,
            'position' => $this->position,
        ]);
    }
}
