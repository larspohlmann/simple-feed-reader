<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\SocialAccount;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Exception\InvalidSelectionException;
use App\Service\Library\Subscription\BulkSubscriber;
use App\Service\Library\Subscription\Exception\SubscriptionLimitReachedException;
use App\Service\Library\Subscription\Model\BulkSubscribeItemModel;
use App\Service\Library\Subscription\OwnedSubscriptions;
use App\Service\Library\Subscription\SubscriptionService;
use App\Service\Library\Tag\TagStructureLock;
use App\Service\Social\Social\Exception\InvalidSocialImportFeedException;
use App\Service\Social\Social\Model\PrivateFeedImportModel;
use App\Service\Social\Social\Model\PrivateFeedModel;
use App\Service\Social\Social\Model\SocialImportFeedModel;
use App\Service\Social\Social\Model\SocialImportModel;
use App\Service\Social\Social\Model\SocialImportResultModel;
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
        private ImportRules $importRules,
        private TagStructureLock $tagStructureLock,
        private ProfileFeeds $profileFeeds,
        private PrivateFeedRules $privateFeedRules,
    ) {
    }

    /**
     * @throws InvalidSocialImportFeedException before anything is written
     * @throws InvalidSelectionException        when a subscription to remove is not the owner's
     */
    public function import(SocialAccount $account, SocialImportModel $import): SocialImportResultModel
    {
        $network = $account->getNetwork();
        $accountOnly = $this->importRules->selectRule($network)->findAccountOnlyFeeds($import->subscribe);
        $items = $this->items($network, self::withoutAccountOnly($import->subscribe, $accountOnly));
        $this->assertCollectionsAllowed($network, $import->collections);
        $privateFeeds = [...$import->collections, ...array_values($accountOnly)];
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
    private function assertCollectionsAllowed(SocialNetwork $network, array $collections): void
    {
        $rule = $this->privateFeedRules->selectRule($network);
        foreach ($collections as $collection) {
            if (!$rule->allows($collection)) {
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
            SocialFeedKind::Search => 'search',
            SocialFeedKind::Local => 'local timeline',
            SocialFeedKind::All => 'All timeline',
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
     * @param list<SocialImportFeedModel>     $feeds
     * @param array<string, PrivateFeedModel> $accountOnly
     *
     * @return list<SocialImportFeedModel>
     */
    private static function withoutAccountOnly(array $feeds, array $accountOnly): array
    {
        return array_values(array_filter(
            $feeds,
            static fn (SocialImportFeedModel $feed): bool => !isset($accountOnly[$feed->identity]),
        ));
    }

    /**
     * @param list<SocialImportFeedModel> $feeds
     *
     * @return list<BulkSubscribeItemModel>
     */
    private function items(SocialNetwork $network, array $feeds): array
    {
        return array_map(
            fn (SocialImportFeedModel $feed): BulkSubscribeItemModel => new BulkSubscribeItemModel(
                $this->requireProfileFeedUrl($network, $feed->identity),
                feedTitle: $feed->title,
            ),
            $feeds,
        );
    }

    private function requireProfileFeedUrl(SocialNetwork $network, string $identity): string
    {
        return $this->profileFeeds->selectRule($network)->buildFeedUrl($identity)
            ?? throw new InvalidSocialImportFeedException(
                sprintf('%s is no %s account.', $identity, $network->displayName()),
            );
    }
}
