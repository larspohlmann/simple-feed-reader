<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Exception\IncompleteStoredMediaException;

/** One declared-width rendition of an entry's lead picture, as a `srcset` candidate names it. */
final readonly class ImageRendition implements \JsonSerializable
{
    /** The frontend's `--magazine-measure` in CSS px: the widest slot a list block gives an image. */
    public const int WIDEST_LIST_SLOT = 680;

    public function __construct(
        public string $url,
        public int $width,
    ) {
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @phpstan-assert-if-true array{url: string, width: positive-int, ...<mixed>} $stored
     */
    public static function isComplete(array $stored): bool
    {
        $width = $stored['width'] ?? null;

        return \is_string($stored['url'] ?? null) && \is_int($width) && $width > 0;
    }

    /** @param array<string, mixed> $stored */
    public static function fromStored(array $stored): self
    {
        if (!self::isComplete($stored)) {
            throw new IncompleteStoredMediaException('A stored image rendition needs a url and a positive width.');
        }

        return new self($stored['url'], $stored['width']);
    }

    /**
     * One rendition per URL and per width, the first declared winning, narrowest first.
     *
     * @param list<self> $renditions
     *
     * @return list<self>
     */
    public static function ladder(array $renditions): array
    {
        $byUrl = [];
        $byWidth = [];
        foreach ($renditions as $rendition) {
            if (isset($byUrl[$rendition->url]) || isset($byWidth[$rendition->width])) {
                continue;
            }
            $byUrl[$rendition->url] = $rendition;
            $byWidth[$rendition->width] = $rendition;
        }
        $ladder = array_values($byUrl);
        usort($ladder, static fn (self $left, self $right): int => $left->width <=> $right->width);

        return $ladder;
    }

    /** @param non-empty-list<self> $renditions */
    public static function widestOf(array $renditions): int
    {
        return max(array_map(static fn (self $rendition): int => $rendition->width, $renditions));
    }

    /**
     * @param list<self> $renditions
     *
     * @return list<array{url: string, width: int}>
     */
    public static function toJsonList(array $renditions): array
    {
        return array_map(static fn (self $rendition): array => $rendition->jsonSerialize(), $renditions);
    }

    /** @return array{url: string, width: int} */
    public function jsonSerialize(): array
    {
        return ['url' => $this->url, 'width' => $this->width];
    }
}
