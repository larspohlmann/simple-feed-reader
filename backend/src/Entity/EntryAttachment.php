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
        return new self(
            (string) $data['url'],
            isset($data['mimeType']) ? (string) $data['mimeType'] : null,
            isset($data['durationInSeconds']) ? (int) $data['durationInSeconds'] : null,
            isset($data['sizeInBytes']) ? (int) $data['sizeInBytes'] : null,
            isset($data['title']) ? (string) $data['title'] : null,
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
