<?php

declare(strict_types=1);

namespace App\Service\Content\Chapter\Model;

enum ChapteredEmbed: string
{
    case YouTube = 'youtube';
    case SoundCloud = 'soundcloud';
}
