<?php

declare(strict_types=1);

namespace App\Service\Content\Chapter\Model;

enum ChapterRunRule
{
    case YouTube;
    case Episode;

    public function admitsStart(int $startSeconds): bool
    {
        return $this === self::Episode || $startSeconds === 0;
    }

    public function skipsUnstampedLines(): bool
    {
        return $this === self::YouTube;
    }
}
