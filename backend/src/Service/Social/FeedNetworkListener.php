<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Feed;
use App\Service\Social\Support\SocialFeedClassifier;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::preFlush, method: 'preFlush', entity: Feed::class)]
final readonly class FeedNetworkListener
{
    public function preFlush(Feed $feed): void
    {
        $feed->classifyAs(SocialFeedClassifier::networkOf($feed));
    }
}
