<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Repository\FeedRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Social\Model\FollowAvatarsModel;
use App\Service\Social\Support\ProfileFeedUrl;
use App\Service\Url\Support\AbsoluteHttpUrl;

final readonly class ImportedProfilePictures
{
    public function __construct(
        private FollowAvatarCache $followAvatars,
        private FeedRepository $feeds,
        private NaiveUtcClock $clock,
    ) {
    }

    /** @param list<string> $feedUrls */
    public function picture(SocialAccount $account, array $feedUrls): void
    {
        $avatars = $this->followAvatars->find($account);
        if ($avatars->isEmpty()) {
            return;
        }
        $now = $this->clock->now();
        foreach ($this->feeds->findByUrlsIndexedByUrl($feedUrls) as $feed) {
            $avatarUrl = self::avatarFor($feed, $avatars);
            if (null !== $avatarUrl && null === $feed->getImageCheckedAt()) {
                $feed->recordImageCheck($avatarUrl, $now);
            }
        }
    }

    private static function avatarFor(Feed $feed, FollowAvatarsModel $avatars): ?string
    {
        $identity = ProfileFeedUrl::identityOf(SocialNetwork::Bluesky, $feed->getUrl());

        $avatarUrl = null === $identity ? null : $avatars->avatarOf($identity);

        return AbsoluteHttpUrl::orNullWithin($avatarUrl, Feed::IMAGE_URL_MAX);
    }
}
