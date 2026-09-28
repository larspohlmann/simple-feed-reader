<?php

declare(strict_types=1);

namespace App\Service\Backup\Factory;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Backup\Dto\FeedLine;
use App\Service\Backup\Dto\SubscriptionLine;
use App\Service\Backup\Dto\TagLine;
use App\Service\Feed\Factory\FeedFactory;
use App\Service\Tag\Factory\TagFactory;
use App\Service\Tag\Model\TagDetailsModel;

/** The rows a backup's foundation lines describe, as the file states them. */
final readonly class RestoredFoundationFactory
{
    public function __construct(private TagFactory $tags, private FeedFactory $feeds)
    {
    }

    public function tag(User $user, TagLine $line): Tag
    {
        return $this->tags->create($user, new TagDetailsModel($line->name, $line->color, $line->icon), $line->position);
    }

    public function feed(FeedLine $line): Feed
    {
        $feed = $this->feeds->create($line->url, $line->sourceFormat, $line->title);
        $feed->setSiteUrl($line->siteUrl);
        $feed->setDescription($line->description);
        $feed->setFaviconUrl($line->faviconUrl);
        $feed->setImageUrl($line->imageUrl);

        return $feed;
    }

    /** Without its tags: only the restore knows the tags this file declared. */
    public function subscription(User $user, Feed $feed, SubscriptionLine $line): Subscription
    {
        $subscription = new Subscription($user, $feed, $line->createdAt);
        $subscription->setCustomTitle($line->customTitle);
        $subscription->setPosition($line->position);
        $subscription->setMarkedReadUntil($line->markedReadUntil);
        $subscription->setIncludeInAllItems($line->includeInAllItems);
        $subscription->setIncludeInForYou($line->includeInForYou);

        return $subscription;
    }
}
