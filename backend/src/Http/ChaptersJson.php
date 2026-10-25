<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EntryChapter;
use App\Entity\EntryMedia;

final class ChaptersJson
{
    /**
     * @param list<EntryChapter> $chapters
     *
     * @return list<array{startSeconds: int, title: string}>|null
     */
    public static function of(array $chapters): ?array
    {
        return $chapters === [] ? null : EntryMedia::toJsonList($chapters);
    }

    private function __construct()
    {
    }
}
