<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

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
        );
    }
}
