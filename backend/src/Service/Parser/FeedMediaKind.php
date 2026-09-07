<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * What a feed media node points at. `Other` is an enclosure that is clearly not
 * an image but not playable audio or video either — a downloadable file.
 * `Unknown` is a node with no type, no medium, and no recognizable extension:
 * it must be left out rather than guessed at.
 */
enum FeedMediaKind
{
    case Image;
    case Audio;
    case Video;
    case Other;
    case Unknown;
}
