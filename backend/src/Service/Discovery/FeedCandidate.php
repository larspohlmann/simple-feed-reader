<?php

declare(strict_types=1);

namespace App\Service\Discovery;

final readonly class FeedCandidate
{
    /**
     * @param string $format the feed's syntax, so the user can tell candidates
     *   apart before subscribing. 'rss' or 'atom' today; a future scraper that
     *   synthesizes a feed from a plain HTML page will add its own value, so
     *   this stays an open string rather than a fixed enum.
     */
    public function __construct(public string $url, public ?string $title, public string $format)
    {
    }
}
