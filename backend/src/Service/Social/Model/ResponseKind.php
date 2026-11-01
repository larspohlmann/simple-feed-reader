<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** What the composer writes about its target post. */
enum ResponseKind: string
{
    case Reply = 'reply';
    case Quote = 'quote';
}
