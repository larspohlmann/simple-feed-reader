<?php

declare(strict_types=1);

namespace App\Service\Library\Opml;

use App\Entity\User;
use App\Enum\SourceFormat;
use App\Repository\SubscriptionRepository;
use App\Service\Library\Opml\Model\OpmlFeedModel;
use App\Service\Library\Opml\Model\OpmlFeedState;
use App\Service\Library\Opml\Model\OpmlPlacedFolderModel;
use App\Service\Library\Opml\Model\OpmlPreviewModel;
use App\Service\Library\Subscription\SubscriptionLimitResolver;
use App\Service\Library\Subscription\Support\SubscribableUrl;

final readonly class OpmlPreviewer
{
    public function __construct(
        private OpmlDocumentReader $documentReader,
        private OpmlPlacementPlanner $placementPlanner,
        private SubscriptionRepository $subscriptions,
        private SubscriptionLimitResolver $subscriptionLimits,
    ) {
    }

    public function preview(User $user, string $opml, ?string $parentTagName): OpmlPreviewModel
    {
        $root = $this->placementPlanner->plan($user, $this->documentReader->read($opml), $parentTagName);
        $userId = $user->requireId();
        $subscribedUrls = array_fill_keys(
            array_column($this->subscriptions->findFeedSummariesForUser($userId), 'feedUrl'),
            true,
        );
        $freeSlots = max(0, $this->subscriptionLimits->resolve($user) - $this->subscriptions->countForUser($userId));

        return new OpmlPreviewModel($freeSlots, $root, self::stateEachFeed($root, $subscribedUrls));
    }

    /**
     * @param array<string, true> $subscribedUrls
     *
     * @return array<string, OpmlFeedState>
     */
    private static function stateEachFeed(OpmlPlacedFolderModel $folder, array $subscribedUrls): array
    {
        $states = [];
        foreach ($folder->feeds as $feed) {
            $states[$feed->url] = self::stateOf($feed, $subscribedUrls);
        }
        foreach ($folder->folders as $child) {
            $states += self::stateEachFeed($child, $subscribedUrls);
        }

        return $states;
    }

    /** @param array<string, true> $subscribedUrls */
    private static function stateOf(OpmlFeedModel $feed, array $subscribedUrls): OpmlFeedState
    {
        return match (true) {
            !SubscribableUrl::isSubscribable($feed->url) => OpmlFeedState::InvalidUrl,
            isset($subscribedUrls[$feed->url]) => OpmlFeedState::Subscribed,
            SourceFormat::SCRAPED === $feed->sourceFormat => OpmlFeedState::Scraped,
            default => OpmlFeedState::New,
        };
    }
}
