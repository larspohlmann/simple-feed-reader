<?php

declare(strict_types=1);

namespace App\Service\Operations\Backup\Dto;

use App\Entity\EntryState;
use App\Entity\Label;
use App\Service\Operations\Backup\Exception\InvalidBackupException;
use App\Service\Operations\Backup\Support\BackupLineFormat;
use App\Service\Operations\Backup\Support\BackupSchema;
use App\Service\Operations\Backup\Support\LineField;

/**
 * The account's per-entry state: read/favourite/kept/viewed flags, their
 * timestamps and the account's label names on the entry, matched to a
 * restored entry by (feed, guidHash) — never by guid, since hashing it
 * outside the Entry constructor is forbidden.
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
        /** @var list<string> */
        public array $labels,
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
            labels: self::parseLabelNames($line),
        );
    }

    /** @param list<string> $labels the names of the account's labels on the state's entry */
    public static function fromState(EntryState $state, string $feedUrl, array $labels): self
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
            labels: $labels,
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
            'labels' => $this->labels,
        ]);
    }

    /**
     * An older file has no `labels`; a name no label can hold is dropped, a value that is no name refuses the file.
     *
     * @param array<string, mixed> $line
     *
     * @return list<string> the trimmed names
     */
    private static function parseLabelNames(array $line): array
    {
        $names = $line['labels'] ?? [];
        if (!\is_array($names)) {
            throw self::buildNotANameListException();
        }

        return array_values(array_filter(
            array_map(self::parseTrimmedName(...), $names),
            static fn (string $name): bool => '' !== $name && mb_strlen($name) <= Label::NAME_MAX_LENGTH,
        ));
    }

    private static function parseTrimmedName(mixed $name): string
    {
        return \is_string($name) ? trim($name) : throw self::buildNotANameListException();
    }

    private static function buildNotANameListException(): InvalidBackupException
    {
        return new InvalidBackupException('Field "labels" is not a list of strings.');
    }
}
