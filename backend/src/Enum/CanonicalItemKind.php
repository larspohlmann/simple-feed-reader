<?php

declare(strict_types=1);

namespace App\Enum;

enum CanonicalItemKind: string
{
    case Bluesky = 'bluesky';
    case Mastodon = 'mastodon';
    case Lemmy = 'lemmy';
}
