<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\Feed;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Library\SavedPosts\Support\SavedFeedUrl;
use App\Service\Social\Social\Support\SocialFeedClassifier;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/** A feed belongs to a network by its saved or private format and address, else by its URL as a profile feed. */
#[AsEntityListener(event: Events::preFlush, method: 'preFlush', entity: Feed::class)]
final readonly class FeedNetworkListener
{
    public function __construct(
        private ProfileFeeds $profileFeeds,
    ) {
    }

    public function preFlush(Feed $feed): void
    {
        $feed->classifyAs($this->findNetwork($feed));
    }

    private function findNetwork(Feed $feed): ?SocialNetwork
    {
        if (SourceFormat::isSaved($feed->getSourceFormat())) {
            return SavedFeedUrl::parseNetwork($feed->getUrl());
        }

        return SocialFeedClassifier::parsePrivateAddress($feed)->network
            ?? $this->profileFeeds->findNetwork($feed->getUrl());
    }
}
