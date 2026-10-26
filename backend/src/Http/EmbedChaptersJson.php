<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EntryChapter;
use App\Entity\EntryMedia;

final class EmbedChaptersJson
{
    /**
     * @param list<EntryChapter> $chapters
     *
     * @return array{chapters: list<array{startSeconds: int, title: string}>}
     */
    public static function of(array $chapters): array
    {
        return ['chapters' => EntryMedia::toJsonList($chapters)];
    }

    private function __construct()
    {
    }
}
