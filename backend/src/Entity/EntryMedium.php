<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * One visual media item stored on an entry: an image or a video, with whatever
 * dimensions and poster the feed declared. `kind` is the string the API emits
 * (`image` or `video`). Unknown fields are omitted from the JSON, so a bare
 * image stores just its URL and kind.
 */
final readonly class EntryMedium implements \JsonSerializable
{
    public function __construct(
        public string $url,
        public string $kind,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $previewImageUrl = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $url = $data['url'] ?? null;
        $kind = $data['kind'] ?? null;
        $width = $data['width'] ?? null;
        $height = $data['height'] ?? null;
        $previewImageUrl = $data['previewImageUrl'] ?? null;

        return new self(
            \is_string($url) ? $url : '',
            \is_string($kind) ? $kind : '',
            \is_int($width) ? $width : null,
            \is_int($height) ? $height : null,
            \is_string($previewImageUrl) ? $previewImageUrl : null,
        );
    }

    /** @return array<string, string|int> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'url' => $this->url,
            'kind' => $this->kind,
            'width' => $this->width,
            'height' => $this->height,
            'previewImageUrl' => $this->previewImageUrl,
        ], static fn (string|int|null $value): bool => $value !== null);
    }
}
