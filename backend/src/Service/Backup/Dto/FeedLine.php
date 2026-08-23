<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

/**
 * One feed the account (or another account sharing it) subscribes to.
 */
final readonly class FeedLine
{
    public function __construct(
        public string $url,
        public ?string $siteUrl,
        public ?string $title,
        public ?string $description,
        public ?string $faviconUrl,
        public ?string $imageUrl,
        public string $sourceFormat,
    ) {
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function fromLine(array $line): self
    {
        return new self(
            url: LineField::string($line, 'url'),
            siteUrl: LineField::stringOrNull($line, 'siteUrl'),
            title: LineField::stringOrNull($line, 'title'),
            description: LineField::stringOrNull($line, 'description'),
            faviconUrl: LineField::stringOrNull($line, 'faviconUrl'),
            imageUrl: LineField::stringOrNull($line, 'imageUrl'),
            sourceFormat: LineField::string($line, 'sourceFormat'),
        );
    }
}
