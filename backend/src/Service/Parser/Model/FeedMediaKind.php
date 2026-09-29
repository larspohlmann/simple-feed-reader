<?php

declare(strict_types=1);

namespace App\Service\Parser\Model;

/**
 * What a feed media node points at. `Other` is a downloadable file, neither image nor playable; `Unknown` has no
 * type, medium or recognizable extension and must be left out, not guessed at.
 */
enum FeedMediaKind
{
    case Image;
    case Audio;
    case Video;
    case Other;
    case Unknown;
}
