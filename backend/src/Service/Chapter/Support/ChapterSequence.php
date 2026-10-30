<?php

declare(strict_types=1);

namespace App\Service\Chapter\Support;

use App\Entity\EntryChapter;

final class ChapterSequence
{
    /**
     * @param list<EntryChapter> $chapters
     *
     * @return list<EntryChapter>
     */
    public static function ordered(array $chapters): array
    {
        usort(
            $chapters,
            static fn (EntryChapter $left, EntryChapter $right): int => $left->startSeconds <=> $right->startSeconds,
        );

        return \count($chapters) >= 2 && self::ascends($chapters) ? $chapters : [];
    }

    /** @param list<EntryChapter> $chapters */
    public static function ascends(array $chapters): bool
    {
        for ($index = 1, $count = \count($chapters); $index < $count; $index++) {
            if ($chapters[$index]->startSeconds <= $chapters[$index - 1]->startSeconds) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<EntryChapter> $chapters
     *
     * @return list<EntryChapter>
     */
    public static function before(array $chapters, ?int $endSeconds): array
    {
        if ($endSeconds === null) {
            return $chapters;
        }

        return array_values(array_filter(
            $chapters,
            static fn (EntryChapter $chapter): bool => $chapter->startSeconds < $endSeconds,
        ));
    }

    private function __construct()
    {
    }
}
