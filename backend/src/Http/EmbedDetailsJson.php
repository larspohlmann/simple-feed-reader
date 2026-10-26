<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EntryMedia;
use App\Service\Chapter\Model\EmbedDetailsModel;

final class EmbedDetailsJson
{
    /** @return array{title: string|null, durationSeconds: int|null, chapters: list<array{startSeconds: int, title: string}>} */
    public static function of(EmbedDetailsModel $details): array
    {
        return [
            'title' => $details->title,
            'durationSeconds' => $details->durationSeconds,
            'chapters' => EntryMedia::toJsonList($details->chapters),
        ];
    }

    private function __construct()
    {
    }
}
