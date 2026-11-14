<?php

declare(strict_types=1);

namespace App\Service\Content\Reader\Media\Model;

enum EmbedKind: string
{
    case Audio = 'audio';
    case Video = 'video';
}
