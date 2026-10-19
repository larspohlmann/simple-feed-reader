<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class NetworkCollectionsModel
{
    /**
     * @param list<SocialCollectionModel> $lists             owned and saved lists, each URI once
     * @param list<SocialCollectionModel> $customFeeds       saved custom feeds that still exist
     * @param list<string>                $goneIds           saved ids the network says no longer exist
     * @param bool                        $truncated         follows or owned lists were cut
     * @param bool                        $savedListsCapped  saved lists by others were cut at the cap
     * @param bool                        $savedFeedsKnown   false when the account keeps no savedFeedsPrefV2
     * @param list<SocialCollectionModel> $tags              followed hashtags; Mastodon only
     */
    public function __construct(
        public array $lists,
        public array $customFeeds,
        public array $goneIds = [],
        public bool $truncated = false,
        public bool $savedListsCapped = false,
        public bool $savedFeedsKnown = true,
        public array $tags = [],
    ) {
    }

    /** Without savedFeedsPrefV2 the saved lists and feeds are unknown too: absent from the answer is not gone. */
    public function isComplete(): bool
    {
        return !$this->truncated && !$this->savedListsCapped && $this->savedFeedsKnown;
    }
}
