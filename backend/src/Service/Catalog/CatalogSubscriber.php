<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\CatalogFeed;
use App\Entity\User;
use App\Repository\CatalogFeedRepository;
use App\Service\Subscription\BulkSubscriber;
use App\Service\Subscription\Model\BulkSubscribeItemModel;
use App\Service\Subscription\Model\BulkSubscribeResultModel;
use App\Service\Subscription\Model\TagStyleModel;

/**
 * Turns a picker selection into subscriptions WITHOUT discovery: catalog rows carry a verified feed URL and format.
 * Unknown, disabled and already-subscribed ids are ignored, so a picker from an edited catalog still submits.
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
    public function subscribe(User $user, array $catalogFeedIds): BulkSubscribeResultModel
    {
        return $this->subscriber->subscribeAll(
            $user,
            array_map(
                static fn (CatalogFeed $feed): BulkSubscribeItemModel => new BulkSubscribeItemModel(
                    feedUrl: $feed->getUrl(),
                    feedTitle: $feed->getTitle(),
                    tagName: $feed->getCategory()->getName(),
                    tagStyle: new TagStyleModel(
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
