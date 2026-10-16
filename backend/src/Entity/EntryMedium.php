<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Exception\IncompleteStoredMediaException;

/**
 * One image, video or animation on an entry, with the dimensions, still and alt text the feed declared. `kind` is
 * the API's string (`image`, `video` or `animation`); an image with a still is an animated GIF. Unset fields stay
 * out of the JSON.
 */
final readonly class EntryMedium implements \JsonSerializable
{
    public function __construct(
        public string $url,
        public string $kind,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $previewImageUrl = null,
        public ?string $alt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @phpstan-assert-if-true array{url: string, kind: string, ...<mixed>} $stored
     */
    public static function isComplete(array $stored): bool
    {
        return \is_string($stored['url'] ?? null) && \is_string($stored['kind'] ?? null);
    }

    /** @param array<string, mixed> $stored */
    public static function fromStored(array $stored): self
    {
        if (!self::isComplete($stored)) {
            throw new IncompleteStoredMediaException('A stored medium needs a url and a kind.');
        }
        $url = $stored['url'];
        $kind = $stored['kind'];
        $width = $stored['width'] ?? null;
        $height = $stored['height'] ?? null;
        $previewImageUrl = $stored['previewImageUrl'] ?? null;
        $alt = $stored['alt'] ?? null;

        return new self(
            $url,
            $kind,
            \is_int($width) ? $width : null,
            \is_int($height) ? $height : null,
            \is_string($previewImageUrl) ? $previewImageUrl : null,
            \is_string($alt) ? $alt : null,
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
            'alt' => $this->alt,
        ], static fn (string|int|null $value): bool => $value !== null);
    }
}
