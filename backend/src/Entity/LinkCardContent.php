<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class LinkCardContent
{
    public const int URL_MAX = 2048;
    public const int TITLE_MAX = 500;
    public const int SITE_NAME_MAX = 255;

    public function __construct(
        public string $url,
        public string $title,
        public ?string $description,
        public ?string $imageUrl,
        public string $siteName,
    ) {
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

    /** @param array<string, mixed>|null $stored */
    public static function fromStoredOrNull(?array $stored): ?self
    {
        $url = $stored['url'] ?? null;
        $title = $stored['title'] ?? null;
        $siteName = $stored['siteName'] ?? null;
        if ($stored === null || !\is_string($url) || !\is_string($title) || !\is_string($siteName)) {
            return null;
        }

        return new self(
            $url,
            $title,
            self::stringOrNull($stored, 'description'),
            self::stringOrNull($stored, 'imageUrl'),
            $siteName,
        );
    }

    /** @param array<string, mixed> $stored */
    private static function stringOrNull(array $stored, string $key): ?string
    {
        $value = $stored[$key] ?? null;

        return \is_string($value) ? $value : null;
    }
}
