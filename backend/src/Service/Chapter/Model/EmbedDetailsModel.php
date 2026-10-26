<?php

declare(strict_types=1);

namespace App\Service\Chapter\Model;

use App\Entity\EntryChapter;
use App\Entity\EntryMedia;

/** An embed's own title, length and chapters (#1824). */
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
        if (!\is_array($stored)) {
            return null;
        }
        $title = $stored['title'] ?? null;
        $durationSeconds = $stored['durationSeconds'] ?? null;
        $chapters = self::chaptersFrom($stored['chapters'] ?? null);
        if (($title !== null && !\is_string($title)) || ($durationSeconds !== null && !\is_int($durationSeconds))) {
            return null;
        }

        return $chapters === null ? null : new self($title, $durationSeconds, $chapters);
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
