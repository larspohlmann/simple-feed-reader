<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Service\Parser\ParsedFeed;

/**
 * A feed discovery has read, not merely located: its canonical URL together
 * with the document that proved it was a feed.
 *
 * Discovery has to parse the document anyway to know it found a feed, and the
 * subscribe that follows would otherwise fetch the very same URL again seconds
 * later — a second request that some sites answer with 429, leaving the new
 * subscription empty (#290). Carrying the document forward turns that second
 * request into no request at all, and a new subscription arrives with its
 * entries already in place.
 */
final readonly class DiscoveredFeed
{
    public function __construct(public string $url, public ParsedFeed $document)
    {
    }
}
