<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

/**
 * One entry belonging to a feed the account subscribes to.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") pure data carrier that
 * mirrors the row 1:1, not a behavioural method.
 */
final readonly class EntryLine
{
    public function __construct(
        public string $feedUrl,
        public string $guid,
        public string $guidHash,
        public ?string $url,
        public string $title,
        public ?string $author,
        public ?string $summary,
        public ?string $contentHtml,
        public ?string $imageUrl,
        public ?int $imageWidth,
        public ?int $imageHeight,
        public ?\DateTimeImmutable $publishedAt,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $effectiveDate,
    ) {
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function fromLine(array $line): self
    {
        return new self(
            feedUrl: LineField::string($line, 'feedUrl'),
            guid: LineField::string($line, 'guid'),
            guidHash: LineField::string($line, 'guidHash'),
            url: LineField::stringOrNull($line, 'url'),
            title: LineField::string($line, 'title'),
            author: LineField::stringOrNull($line, 'author'),
            summary: LineField::stringOrNull($line, 'summary'),
            contentHtml: LineField::stringOrNull($line, 'contentHtml'),
            imageUrl: LineField::stringOrNull($line, 'imageUrl'),
            imageWidth: LineField::intOrNull($line, 'imageWidth'),
            imageHeight: LineField::intOrNull($line, 'imageHeight'),
            publishedAt: LineField::dateOrNull($line, 'publishedAt'),
            createdAt: LineField::date($line, 'createdAt'),
            effectiveDate: LineField::date($line, 'effectiveDate'),
        );
    }
}
