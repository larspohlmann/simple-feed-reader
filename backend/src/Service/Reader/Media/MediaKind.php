<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

enum MediaKind: string
{
    case Audio = 'audio';
    case Video = 'video';
    case Embed = 'embed';
}
