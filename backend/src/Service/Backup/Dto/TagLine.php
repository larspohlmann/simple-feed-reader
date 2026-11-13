<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Entity\Tag;
use App\Enum\InclusionSurface;
use App\Service\Backup\Support\BackupLineFormat;
use App\Service\Backup\Support\BackupSchema;
use App\Service\Backup\Support\LineField;
use App\Service\Backup\Support\LineFieldWithDefault;

/**
 * One of the account's tags.
 */
final readonly class TagLine
{
    public function __construct(
        public string $name,
        public ?string $color,
        public ?string $icon,
        public int $position,
        public ?string $parent = null,
        public bool $includeInAllItems = true,
        public bool $includeInForYou = true,
        public bool $includeInSavedSearches = true,
        public bool $includeInTrending = true,
    ) {
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function fromLine(array $line): self
    {
        return new self(
            name: LineField::string($line, 'name'),
            color: LineField::stringOrNull($line, 'color'),
            icon: LineField::stringOrNull($line, 'icon'),
            position: LineField::int($line, 'position'),
            parent: LineField::stringOrNull($line, 'parent'),
            includeInAllItems: LineFieldWithDefault::bool($line, 'includeInAllItems', true),
            includeInForYou: LineFieldWithDefault::bool($line, 'includeInForYou', true),
            includeInSavedSearches: LineFieldWithDefault::bool($line, 'includeInSavedSearches', true),
            includeInTrending: LineFieldWithDefault::bool($line, 'includeInTrending', true),
        );
    }

    public static function of(Tag $tag): self
    {
        return new self(
            name: $tag->getName(),
            color: $tag->getColor(),
            icon: $tag->getIcon(),
            position: $tag->getPosition(),
            parent: $tag->getParent()?->getName(),
            includeInAllItems: $tag->isIncludedIn(InclusionSurface::AllItems),
            includeInForYou: $tag->isIncludedIn(InclusionSurface::ForYou),
            includeInSavedSearches: $tag->isIncludedIn(InclusionSurface::SavedSearches),
            includeInTrending: $tag->isIncludedIn(InclusionSurface::Trending),
        );
    }

    public function toBackupLine(): string
    {
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_TAG,
            'name' => $this->name,
            'color' => $this->color,
            'icon' => $this->icon,
            'position' => $this->position,
            'parent' => $this->parent,
            'includeInAllItems' => $this->includeInAllItems,
            'includeInForYou' => $this->includeInForYou,
            'includeInSavedSearches' => $this->includeInSavedSearches,
            'includeInTrending' => $this->includeInTrending,
        ]);
    }
}
