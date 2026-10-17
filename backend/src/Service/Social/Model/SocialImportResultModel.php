<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Service\Subscription\Model\BulkSubscribeResultModel;

final readonly class SocialImportResultModel
{
    private function __construct(
        public int $imported,
        public int $alreadySubscribed,
        public int $invalid,
        public int $skippedOverLimit,
        public int $removed,
    ) {
    }

    public static function from(
        BulkSubscribeResultModel $subscribed,
        PrivateFeedImportModel $privateFeeds,
        int $removed,
    ): self {
        return new self(
            $subscribed->imported + $privateFeeds->added,
            $subscribed->alreadySubscribed + $privateFeeds->alreadySubscribed,
            $subscribed->invalid,
            $subscribed->skippedOverLimit + $privateFeeds->overLimit,
            $removed,
        );
    }
}
