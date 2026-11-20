<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Repository\FeedRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Social\Social\Model\FollowAvatarsModel;
use App\Service\Social\Social\ProfileFeedRule\ProfileFeedRuleInterface;

final readonly class ImportedProfilePictures
{
    public function __construct(
        private FollowAvatarCache $followAvatars,
        private FeedRepository $feeds,
        private NaiveUtcClock $clock,
        private ProfileFeeds $profileFeeds,
    ) {
    }

    /** @param list<string> $feedUrls */
    public function picture(SocialAccount $account, array $feedUrls): void
    {
        $avatars = $this->followAvatars->find($account);
        $now = $this->clock->now();
        $rule = $this->profileFeeds->selectRule($account->getNetwork());
        foreach ($this->feeds->findByUrlsIndexedByUrl($feedUrls) as $feed) {
            if (null !== $feed->getImageCheckedAt()) {
                continue;
            }
            $avatarUrl = self::findAvatar($feed, $rule, $avatars);
            if (null !== $avatarUrl) {
                $feed->recordImageCheck($avatarUrl, $now);
            }
        }
    }

    private static function findAvatar(Feed $feed, ProfileFeedRuleInterface $rule, FollowAvatarsModel $avatars): ?string
    {
        $identity = $rule->parseIdentity($feed->getUrl());
        if (null === $identity) {
            return null;
        }

        return AbsoluteHttpUrl::keepIfAbsoluteWithin($avatars->readAvatar($identity), Feed::IMAGE_URL_MAX);
    }
}
