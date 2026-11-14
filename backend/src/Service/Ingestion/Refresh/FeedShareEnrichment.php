<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh;

use App\Entity\Feed;
use App\Service\Social\Bluesky\PostEnricher;
use App\Service\Social\Social\SelfThreadEnricher;

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
