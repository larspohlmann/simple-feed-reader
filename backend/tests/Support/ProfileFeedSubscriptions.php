<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProfileFeedSubscriptions
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function subscribe(User $user, string $feedUrl, ?string $feedTitle = null): Subscription
    {
        $feed = new Feed($feedUrl);
        $feed->setTitle($feedTitle);
        $this->entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-10-01 10:00:00'));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    public function subscribeTagged(User $user, string $feedUrl, string $tagName): Subscription
    {
        $tag = new Tag($user, $tagName);
        $tag->setPosition(0);
        $this->entityManager->persist($tag);
        $subscription = $this->subscribe($user, $feedUrl);
        $subscription->addTag($tag, 0);
        $this->entityManager->flush();

        return $subscription;
    }
}
