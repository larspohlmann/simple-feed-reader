<?php

declare(strict_types=1);

namespace App\Service\Chapter\Model;

use App\Entity\EntryChapter;
use App\Entity\EntryMedia;

final readonly class EmbedDetailsModel
{
    /** @param list<EntryChapter> $chapters */
    public function __construct(
        public ?string $title,
        public ?int $durationSeconds,
        public array $chapters,
        public ?string $artworkUrl,
    ) {
    }

    /** @return array{title: string|null, durationSeconds: int|null, chapters: list<array{startSeconds: int, title: string}>, artworkUrl: string|null} */
    public function toCacheEntry(): array
    {
        return [
            'title' => $this->title,
            'durationSeconds' => $this->durationSeconds,
            'chapters' => EntryMedia::toJsonList($this->chapters),
            'artworkUrl' => $this->artworkUrl,
        ];
    }

    public static function fromCacheEntryOrNull(mixed $stored): ?self
    {
        if (!\is_array($stored) || !self::hasEveryKey($stored)) {
            return null;
        }
        $title = $stored['title'];
        $durationSeconds = $stored['durationSeconds'];
        $chapters = self::chaptersFrom($stored['chapters']);
        $artworkUrl = $stored['artworkUrl'];
        if (!self::isOptionalText($title) || !self::isOptionalText($artworkUrl)) {
            return null;
        }
        if (!self::isDuration($durationSeconds) || $chapters === null) {
            return null;
        }

        return new self($title, $durationSeconds, $chapters, $artworkUrl);
    }

    /** @param array<mixed> $stored */
    private static function hasEveryKey(array $stored): bool
    {
        return \array_key_exists('title', $stored)
            && \array_key_exists('durationSeconds', $stored)
            && \array_key_exists('chapters', $stored)
            && \array_key_exists('artworkUrl', $stored);
    }

    /** @phpstan-assert-if-true string|null $text */
    private static function isOptionalText(mixed $text): bool
    {
        return $text === null || \is_string($text);
    }

    /** @phpstan-assert-if-true int|null $durationSeconds */
    private static function isDuration(mixed $durationSeconds): bool
    {
        return $durationSeconds === null || (\is_int($durationSeconds) && $durationSeconds > 0);
    }

    /** @return list<EntryChapter>|null */
    private static function chaptersFrom(mixed $stored): ?array
    {
        if (!\is_array($stored) || !array_is_list($stored)) {
            return null;
        }
        $chapters = [];
        foreach ($stored as $chapter) {
            if (!\is_array($chapter) || array_is_list($chapter)) {
                return null;
            }
            /** @var array<string, mixed> $chapter */
            if (!EntryChapter::isComplete($chapter)) {
                return null;
            }
            $chapters[] = EntryChapter::fromStored($chapter);
        }

        return $chapters;
    }
}
