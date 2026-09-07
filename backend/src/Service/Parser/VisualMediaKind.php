<?php

declare(strict_types=1);

namespace App\Service\Parser;

/** What a `media[]` element shows. Backed by the string the API emits. */
enum VisualMediaKind: string
{
    case Image = 'image';
    case Video = 'video';
}
