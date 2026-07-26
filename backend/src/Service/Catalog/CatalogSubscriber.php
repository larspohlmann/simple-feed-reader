<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\CatalogFeed;
use App\Entity\User;
use App\Repository\CatalogFeedRepository;
use App\Service\Subscription\BulkSubscribeItem;
use App\Service\Subscription\BulkSubscribeResult;
use App\Service\Subscription\BulkSubscriber;
use App\Service\Subscription\TagStyle;

/**
 * Turns a picker selection into subscriptions. NO DISCOVERY: catalog rows carry
 * a verified direct feed URL and its sourceFormat, so 110 selections must never
 * become 110 outbound discovery fetches.
 *
 * Unknown, disabled and already-subscribed ids are ignored rather than rejected,
 * so a picker rendered against a since-edited catalog still submits cleanly.
 */
final readonly class CatalogSubscriber
{
    public function __construct(
        private CatalogFeedRepository $feeds,
        private BulkSubscriber $subscriber,
    ) {
    }

    /**
     * @param list<int> $catalogFeedIds
     */
    public function subscribe(User $user, array $catalogFeedIds): BulkSubscribeResult
    {
        return $this->subscriber->subscribeAll(
            $user,
            array_map(
                static fn (CatalogFeed $feed): BulkSubscribeItem => new BulkSubscribeItem(
                    feedUrl: $feed->getUrl(),
                    feedTitle: $feed->getTitle(),
                    tagName: $feed->getCategory()->getName(),
                    tagStyle: new TagStyle(
                        $feed->getCategory()->getColor(),
                        $feed->getCategory()->getIcon(),
                    ),
                    sourceFormat: $feed->getSourceFormat(),
                ),
                // Ordered by category position then feed position, so tags are
                // created in catalog order and feeds sit in catalog order inside
                // each tag.
                $this->feeds->findEnabledByIds($catalogFeedIds),
            ),
        );
    }
}
