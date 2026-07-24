<?php

declare(strict_types=1);

namespace App\Service\Discovery;

final readonly class FeedCandidate
{
    /**
     * @param string $format the feed's syntax, so the user can tell candidates
     *   apart before subscribing: 'rss' or 'atom' for advertised feed
     *   documents, 'scraped' for the fallback candidate that synthesizes a
     *   feed from the HTML page itself. An open string rather than a fixed
     *   enum, so the next extraction strategy can add its value without
     *   touching this class.
     */
    public function __construct(public string $url, public ?string $title, public string $format)
    {
    }
}
