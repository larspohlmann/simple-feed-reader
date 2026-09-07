<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * One playable or downloadable enclosure stored on an entry — a podcast audio
 * file, a video, or another file. Everything but the URL is what the feed
 * declared and is omitted from the JSON when the feed did not state it.
 */
final readonly class EntryAttachment implements \JsonSerializable
{
    public function __construct(
        public string $url,
        public ?string $mimeType = null,
        public ?int $durationInSeconds = null,
        public ?int $sizeInBytes = null,
        public ?string $title = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $url = $data['url'] ?? null;
        $mimeType = $data['mimeType'] ?? null;
        $durationInSeconds = $data['durationInSeconds'] ?? null;
        $sizeInBytes = $data['sizeInBytes'] ?? null;
        $title = $data['title'] ?? null;

        return new self(
            \is_string($url) ? $url : '',
            \is_string($mimeType) ? $mimeType : null,
            \is_int($durationInSeconds) ? $durationInSeconds : null,
            \is_int($sizeInBytes) ? $sizeInBytes : null,
            \is_string($title) ? $title : null,
        );
    }

    /** @return array<string, string|int> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'url' => $this->url,
            'mimeType' => $this->mimeType,
            'durationInSeconds' => $this->durationInSeconds,
            'sizeInBytes' => $this->sizeInBytes,
            'title' => $this->title,
        ], static fn (string|int|null $value): bool => $value !== null);
    }
}
