<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Exception\InvalidSelectionException;
use App\Service\Social\Exception\InvalidSocialImportFeedException;
use App\Service\Social\Model\SocialImportFeedModel;
use App\Service\Social\Model\SocialImportModel;
use App\Service\Social\Model\SocialImportResultModel;
use App\Service\Social\Support\ProfileFeedUrl;
use App\Service\Subscription\BulkSubscriber;
use App\Service\Subscription\Model\BulkSubscribeItemModel;
use App\Service\Subscription\OwnedSubscriptions;
use App\Service\Subscription\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SocialImporter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BulkSubscriber $bulkSubscriber,
        private OwnedSubscriptions $ownedSubscriptions,
        private SubscriptionService $subscriptionService,
    ) {
    }

    /**
     * @throws InvalidSocialImportFeedException before anything is written
     * @throws InvalidSelectionException        when a subscription to remove is not the owner's
     */
    public function import(SocialAccount $account, SocialImportModel $import): SocialImportResultModel
    {
        $network = $account->getNetwork();
        $user = $account->getUser();
        $this->assertProfileFeedsOf($network, $import->subscribe);
        $toRemove = $this->ownedSubscriptions->resolve($user->requireId(), $import->unsubscribe);

        // Removals first, so the slots they free count toward the subscription cap.
        return $this->entityManager->wrapInTransaction(
            function () use ($user, $network, $import, $toRemove): SocialImportResultModel {
                $removed = $this->subscriptionService->unsubscribeAll(array_values($toRemove));
                $subscribed = $this->bulkSubscriber->subscribeAll($user, self::items($network, $import->subscribe));

                return SocialImportResultModel::from($subscribed, $removed);
            },
        );
    }

    /** @param list<SocialImportFeedModel> $feeds */
    private function assertProfileFeedsOf(SocialNetwork $network, array $feeds): void
    {
        foreach ($feeds as $feed) {
            if (null === ProfileFeedUrl::identityOf($network, $feed->feedUrl)) {
                throw new InvalidSocialImportFeedException(
                    sprintf('%s is no %s profile feed.', $feed->feedUrl, self::nameOf($network)),
                );
            }
        }
    }

    /**
     * @param list<SocialImportFeedModel> $feeds
     *
     * @return list<BulkSubscribeItemModel>
     */
    private static function items(SocialNetwork $network, array $feeds): array
    {
        $tagName = self::nameOf($network);

        return array_map(
            static fn (SocialImportFeedModel $feed): BulkSubscribeItemModel => new BulkSubscribeItemModel(
                $feed->feedUrl,
                feedTitle: $feed->title,
                tagName: $tagName,
            ),
            $feeds,
        );
    }

    private static function nameOf(SocialNetwork $network): string
    {
        return match ($network) {
            SocialNetwork::Bluesky => 'Bluesky',
            SocialNetwork::Mastodon => 'Mastodon',
        };
    }
}
