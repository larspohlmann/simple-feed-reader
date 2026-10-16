<?php

declare(strict_types=1);

namespace App\Service\Parser\Model;

/** What a `media[]` element shows. Backed by the string the API emits. */
enum VisualMediaKind: string
{
    case Image = 'image';
    case Video = 'video';
    /** A silent clip shown like an animated GIF: it loops on its own, with no controls and no sound. */
    case Animation = 'animation';
}
