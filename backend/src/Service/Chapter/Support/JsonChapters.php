<?php

declare(strict_types=1);

namespace App\Service\Chapter\Support;

use App\Entity\EntryChapter;

/** A Podcasting 2.0 JSON chapters file (`podcast:chapters`) as an ordered chapter list. */
final class JsonChapters
{
    private const int LATEST_START_SECONDS = 999_999_999;

    /**
     * @param array<mixed> $file
     *
     * @return list<EntryChapter>
     */
    public static function from(array $file): array
    {
        $rows = $file['chapters'] ?? null;
        if (!\is_array($rows)) {
            return [];
        }

        $chapters = [];
        foreach ($rows as $row) {
            $chapter = \is_array($row) ? self::chapterOf($row) : null;
            if ($chapter !== null) {
                $chapters[] = $chapter;
            }
        }

        return ChapterSequence::ordered($chapters);
    }

    /** @param array<mixed> $row */
    private static function chapterOf(array $row): ?EntryChapter
    {
        $start = $row['startTime'] ?? null;
        $title = \is_string($row['title'] ?? null) ? trim($row['title']) : '';
        $listed = ($row['toc'] ?? true) !== false;

        $startsInRange = (\is_int($start) || \is_float($start))
            && $start >= 0
            && $start <= self::LATEST_START_SECONDS;

        return $startsInRange && $title !== '' && $listed
            ? new EntryChapter((int) floor($start), $title)
            : null;
    }

    private function __construct()
    {
    }
}
