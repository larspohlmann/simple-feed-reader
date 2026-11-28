<?php

declare(strict_types=1);

namespace App\Service\Operations\Backup\Dto;

use App\Entity\InclusionSwitches;
use App\Entity\Tag;
use App\Service\Operations\Backup\Support\BackupInclusions;
use App\Service\Operations\Backup\Support\BackupLineFormat;
use App\Service\Operations\Backup\Support\BackupSchema;
use App\Service\Operations\Backup\Support\LineField;

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
        public InclusionSwitches $inclusions = new InclusionSwitches(),
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
            inclusions: BackupInclusions::fromLine($line),
        );
    }

    public static function fromTag(Tag $tag): self
    {
        return new self(
            name: $tag->getName(),
            color: $tag->getColor(),
            icon: $tag->getIcon(),
            position: $tag->getPosition(),
            parent: $tag->getParent()?->getName(),
            inclusions: $tag->getInclusions(),
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
            ...BackupInclusions::toFields($this->inclusions),
        ]);
    }
}
