<?php

declare(strict_types=1);

namespace App\Service\Library\Opml\Model;

enum OpmlFeedState: string
{
    case New = 'new';
    case Subscribed = 'subscribed';
    case Scraped = 'scraped';
    case InvalidUrl = 'invalid_url';
}
