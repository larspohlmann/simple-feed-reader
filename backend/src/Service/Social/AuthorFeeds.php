<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Repository\SocialFeedRepository;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Support\PrivateFeedSite;
use App\Service\Social\Support\SocialFeedUrl;
use App\Service\Subscription\Exception\AlreadySubscribedException;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use App\Service\Subscription\SubscriptionCreator;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AuthorFeeds
{
    public function __construct(
        private SocialFeedRepository $socialFeeds,
        private SubscriptionCreator $subscriptionCreator,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<Tag> $tags
     *
     * @throws SubscriptionLimitReachedException with nothing written and the entity manager still open
     * @throws AlreadySubscribedException
     */
    public function create(SocialAccount $account, HiddenProfileModel $profile, array $tags): Subscription
    {
        $author = PrivateFeedModel::author($profile->did, $profile->name);
        $this->entityManager->beginTransaction();
        try {
            $subscription = $this->subscriptionCreator->create(
                $account->getUser(),
                SocialFeedUrl::of($account, $author),
                SourceFormat::BLUESKY_API,
                $tags,
                $author->name,
            );
            $this->recordAuthor($account, $subscription->getFeed(), $author);
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }

        return $subscription;
    }

    private function recordAuthor(SocialAccount $account, Feed $feed, PrivateFeedModel $author): void
    {
        $feed->setSiteUrl(PrivateFeedSite::of($account, $author));
        if (null === $this->socialFeeds->findOneFor($account, SocialFeedKind::Author, $author->remoteId)) {
            $this->entityManager->persist(new SocialFeed($account, $feed, SocialFeedKind::Author, $author->remoteId));
        }
        $this->entityManager->flush();
    }
}
