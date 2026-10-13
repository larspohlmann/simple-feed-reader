<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Enum\SourceFormat;
use App\Service\Social\Exception\PrivateFeedOrphanedException;

/** One pass's feeds as its budget starts them, and the social feed behind each private one. */
final readonly class PassFeeds
{
    /** @var array<int, true> */
    private array $privateFeedIds;

    /** @param array<int, SocialFeed> $socialFeedsByFeedId each with its account loaded */
    public function __construct(private RefreshPass $pass, private array $socialFeedsByFeedId)
    {
        $this->privateFeedIds = array_fill_keys(self::privateFeedIdsOf($pass), true);
    }

    /** @return list<int> every private feed's id, started or not */
    public static function privateFeedIdsOf(RefreshPass $pass): array
    {
        $privateFeedIds = [];
        foreach ($pass->feeds as $feed) {
            if (SourceFormat::isSocial($feed->getSourceFormat())) {
                $privateFeedIds[] = $feed->requireId();
            }
        }

        return $privateFeedIds;
    }

    /** @return \Generator<int, Feed, mixed, void> */
    public function started(): \Generator
    {
        return $this->pass->startedFeeds();
    }

    public function isPrivate(int|string $feedId): bool
    {
        return isset($this->privateFeedIds[$feedId]);
    }

    /** @throws PrivateFeedOrphanedException when the private feed has lost its social feed */
    public function socialFeedOf(int $feedId): SocialFeed
    {
        return $this->socialFeedsByFeedId[$feedId] ?? throw new PrivateFeedOrphanedException();
    }
}
