<?php

declare(strict_types=1);

namespace App\Service\Chapter\Model;

enum ChapterRunStart
{
    case AtZero;
    case Anywhere;

    public function admits(int $startSeconds): bool
    {
        return $this === self::Anywhere || $startSeconds === 0;
    }
}
