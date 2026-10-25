<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EntryChapter;

final class ChaptersJson
{
    /**
     * @param list<EntryChapter> $chapters
     *
     * @return list<array{startSeconds: int, title: string}>|null
     */
    public static function of(array $chapters): ?array
    {
        if ($chapters === []) {
            return null;
        }

        return array_map(static fn (EntryChapter $chapter): array => $chapter->jsonSerialize(), $chapters);
    }

    private function __construct()
    {
    }
}
