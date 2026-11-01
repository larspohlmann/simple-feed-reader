<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** What the composer sends: a reply, a quote, or a post of a shared link. */
enum ComposerKind: string
{
    case Reply = 'reply';
    case Quote = 'quote';
    case Link = 'link';
}
