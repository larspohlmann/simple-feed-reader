<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Model;

enum EmbedShape: string
{
    case Landscape = 'landscape';
    case Tall = 'tall';
    case Portrait = 'portrait';
}
