<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Service\Ingestion\Ingest\FeedTitle\FeedTitleInterface;

final readonly class ProfileFeedTitle implements FeedTitleInterface
{
    public function __construct(
        private ProfileFeeds $profileFeeds,
    ) {
    }

    public function of(string $feedUrl, string $channelTitle): string
    {
        return $this->profileFeeds->findRule($feedUrl)?->formatTitle($feedUrl, $channelTitle) ?? $channelTitle;
    }
}
