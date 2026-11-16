<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class LinkCardContent
{
    public const int URL_MAX = 2048;
    public const int TITLE_MAX = 500;
    public const int DESCRIPTION_MAX = 300;
    public const int SITE_NAME_MAX = 255;

    public function __construct(
        public string $url,
        public string $title,
        public ?string $description,
        public ?string $imageUrl,
        public string $siteName,
    ) {
    }

    #[\NoDiscard]
    public function withUrl(string $url): self
    {
        return clone($this, ['url' => $url]);
    }

    /** @param array{url: string, title: string, description: ?string, imageUrl: ?string, siteName: string} $row */
    public static function fromRow(array $row): self
    {
        return new self($row['url'], $row['title'], $row['description'], $row['imageUrl'], $row['siteName']);
    }

    /** @return array{url: string, title: string, description: ?string, imageUrl: ?string, siteName: string} */
    public function toStored(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'description' => $this->description,
            'imageUrl' => $this->imageUrl,
            'siteName' => $this->siteName,
        ];
    }
}
