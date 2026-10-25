<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Exception\InvalidSelectionException;
use App\Service\Social\Exception\InvalidSocialImportFeedException;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\PrivateFeedImportModel;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\SocialImportFeedModel;
use App\Service\Social\Model\SocialImportModel;
use App\Service\Social\Model\SocialImportResultModel;
use App\Service\Social\Support\PrivateFeedRules;
use App\Service\Social\Support\ProfileFeedUrl;
use App\Service\Subscription\BulkSubscriber;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use App\Service\Subscription\Model\BulkSubscribeItemModel;
use App\Service\Subscription\OwnedSubscriptions;
use App\Service\Subscription\SubscriptionService;
use App\Service\Tag\TagStructureLock;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SocialImporter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BulkSubscriber $bulkSubscriber,
        private OwnedSubscriptions $ownedSubscriptions,
        private SubscriptionService $subscriptionService,
        private PrivateFeeds $privateFeeds,
        private ImportedProfilePictures $importedPictures,
        private HiddenImportFollows $hiddenFollows,
        private TagStructureLock $tagStructureLock,
    ) {
    }

    /**
     * @throws InvalidSocialImportFeedException before anything is written
     * @throws InvalidSelectionException        when a subscription to remove is not the owner's
     */
    public function import(SocialAccount $account, SocialImportModel $import): SocialImportResultModel
    {
        $network = $account->getNetwork();
        $hidden = $this->hiddenFollows->among($account, $import->subscribe);
        $items = self::items($network, self::withoutHidden($import->subscribe, $hidden));
        self::assertCollectionsOf($network, $import->collections);
        $privateFeeds = [...$import->collections, ...self::authorFeedsOf($hidden)];
        $toRemove = $this->ownedSubscriptions->resolve($account->getUser()->requireId(), $import->unsubscribe);

        // Removals first, so the slots they free count toward the subscription cap.
        return $this->tagStructureLock->hold(
            $account->getUser(),
            function () use ($account, $items, $privateFeeds, $toRemove): SocialImportResultModel {
                $removed = $this->subscriptionService->unsubscribeAll(array_values($toRemove));
                $subscribed = $this->bulkSubscriber->subscribeAll($account->getUser(), $items);
                $this->importedPictures->picture(
                    $account,
                    array_map(static fn (BulkSubscribeItemModel $item): string => $item->feedUrl, $items),
                );
                $result = SocialImportResultModel::from(
                    $subscribed,
                    $this->addPrivateFeeds($account, $privateFeeds),
                    $removed,
                );
                $this->entityManager->flush();

                return $result;
            },
        );
    }

    /** @param list<PrivateFeedModel> $collections */
    private static function assertCollectionsOf(SocialNetwork $network, array $collections): void
    {
        foreach ($collections as $collection) {
            if (!PrivateFeedRules::allows($network, $collection)) {
                throw new InvalidSocialImportFeedException(sprintf(
                    '%s is no %s %s.',
                    '' === $collection->remoteId ? $collection->name : $collection->remoteId,
                    $network->displayName(),
                    self::kindLabel($collection->kind),
                ));
            }
        }
    }

    private static function kindLabel(SocialFeedKind $kind): string
    {
        return match ($kind) {
            SocialFeedKind::Home => 'home timeline',
            SocialFeedKind::UserList => 'list',
            SocialFeedKind::CustomFeed => 'custom feed',
            SocialFeedKind::Author => 'profile',
            SocialFeedKind::Tag => 'followed tag',
            SocialFeedKind::Trending => 'trending timeline',
        };
    }

    /** @param list<PrivateFeedModel> $privateFeeds */
    private function addPrivateFeeds(SocialAccount $account, array $privateFeeds): PrivateFeedImportModel
    {
        $tally = new PrivateFeedImportModel();
        foreach ($privateFeeds as $privateFeed) {
            try {
                $tally = $tally->counting($this->privateFeeds->create($account, $privateFeed));
            } catch (SubscriptionLimitReachedException) {
                $tally = $tally->countingOverLimit();
            }
        }

        return $tally;
    }

    /**
     * @param list<SocialImportFeedModel>       $feeds
     * @param array<string, HiddenProfileModel> $hidden
     *
     * @return list<SocialImportFeedModel>
     */
    private static function withoutHidden(array $feeds, array $hidden): array
    {
        return array_values(array_filter(
            $feeds,
            static fn (SocialImportFeedModel $feed): bool => !isset($hidden[$feed->identity]),
        ));
    }

    /**
     * @param array<string, HiddenProfileModel> $hidden
     *
     * @return list<PrivateFeedModel>
     */
    private static function authorFeedsOf(array $hidden): array
    {
        $authorFeeds = [];
        foreach ($hidden as $profile) {
            $authorFeeds[] = $profile->toAuthorFeed();
        }

        return $authorFeeds;
    }

    /**
     * @param list<SocialImportFeedModel> $feeds
     *
     * @return list<BulkSubscribeItemModel>
     */
    private static function items(SocialNetwork $network, array $feeds): array
    {
        return array_map(
            static fn (SocialImportFeedModel $feed): BulkSubscribeItemModel => new BulkSubscribeItemModel(
                self::profileFeedOf($network, $feed->identity),
                feedTitle: $feed->title,
            ),
            $feeds,
        );
    }

    private static function profileFeedOf(SocialNetwork $network, string $identity): string
    {
        return ProfileFeedUrl::of($network, $identity) ?? throw new InvalidSocialImportFeedException(
            sprintf('%s is no %s account.', $identity, $network->displayName()),
        );
    }
}
