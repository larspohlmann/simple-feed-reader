<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Service\Bluesky\PostEnricher;
use App\Service\Social\SelfThreadEnricher;

/** What a refresh settles for a feed once its entries are stored: Bluesky embeds first, then self-reply threads. */
final readonly class FeedShareEnrichment
{
    public function __construct(private PostEnricher $postEnricher, private SelfThreadEnricher $selfThreads)
    {
    }

    public function enrich(Feed $feed): void
    {
        $this->postEnricher->enrich($feed);
        $this->selfThreads->enrich($feed);
    }
}
