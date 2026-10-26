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
    ) {
    }

    /** @return array{title: string|null, durationSeconds: int|null, chapters: list<array{startSeconds: int, title: string}>} */
    public function toCacheEntry(): array
    {
        return [
            'title' => $this->title,
            'durationSeconds' => $this->durationSeconds,
            'chapters' => EntryMedia::toJsonList($this->chapters),
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
        if (!self::isTitle($title) || !self::isDuration($durationSeconds) || $chapters === null) {
            return null;
        }

        return new self($title, $durationSeconds, $chapters);
    }

    /** @param array<mixed> $stored */
    private static function hasEveryKey(array $stored): bool
    {
        return \array_key_exists('title', $stored)
            && \array_key_exists('durationSeconds', $stored)
            && \array_key_exists('chapters', $stored);
    }

    /** @phpstan-assert-if-true string|null $title */
    private static function isTitle(mixed $title): bool
    {
        return $title === null || \is_string($title);
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
