<?php

declare(strict_types=1);

namespace App\Service\FeedDirectory;

use App\Repository\FeedRepository;
use App\Service\FeedDirectory\Model\SubscribedFeedsModel;
use App\Service\FeedDirectory\Support\FeedDirectoryUrl;

final readonly class SubscribedFeeds
{
    public function __construct(
        private FeedRepository $feeds,
    ) {
    }

    public function of(int $userId): SubscribedFeedsModel
    {
        $urls = array_keys($this->feeds->subscribedUrlSetForUser($userId));

        return new SubscribedFeedsModel(
            self::sortedUnique(array_map(FeedDirectoryUrl::normalise(...), $urls)),
            self::sortedUnique(array_map(FeedDirectoryUrl::host(...), $urls)),
        );
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sortedUnique(array $values): array
    {
        $unique = array_unique($values);
        sort($unique);

        return $unique;
    }
}
