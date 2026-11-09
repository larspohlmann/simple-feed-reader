<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Entity\Tag;
use App\Service\Backup\Support\BackupLineFormat;
use App\Service\Backup\Support\BackupSchema;
use App\Service\Backup\Support\LineField;

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
        ]);
    }
}
