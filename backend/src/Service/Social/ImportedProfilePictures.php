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
        $now = $this->clock->now();
        foreach ($this->feeds->findByUrlsIndexedByUrl($feedUrls) as $feed) {
            if (null !== $feed->getImageCheckedAt()) {
                continue;
            }
            $avatarUrl = self::avatarFor($feed, $account->getNetwork(), $avatars);
            if (null !== $avatarUrl) {
                $feed->recordImageCheck($avatarUrl, $now);
            }
        }
    }

    private static function avatarFor(Feed $feed, SocialNetwork $network, FollowAvatarsModel $avatars): ?string
    {
        $identity = ProfileFeedUrl::identityOf($network, $feed->getUrl());
        if (null === $identity) {
            return null;
        }

        return AbsoluteHttpUrl::orNullWithin($avatars->avatarOf($identity), Feed::IMAGE_URL_MAX);
    }
}
