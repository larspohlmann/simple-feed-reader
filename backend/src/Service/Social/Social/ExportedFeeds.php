<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\Subscription;
use App\Enum\SourceFormat;
use App\Service\Social\Social\Model\ExportedSubscriptionModel;

final readonly class ExportedFeeds
{
    public function __construct(
        private ProfileFeeds $profileFeeds,
    ) {
    }

    public function buildExport(Subscription $subscription): ExportedSubscriptionModel
    {
        $feed = $subscription->getFeed();
        $exportUrl = $this->profileFeeds->findExportUrl($feed->getUrl());

        return null === $exportUrl
            ? new ExportedSubscriptionModel($subscription, $feed->getUrl(), $feed->getSourceFormat())
            : new ExportedSubscriptionModel($subscription, $exportUrl, SourceFormat::XML);
    }

    /**
     * @param list<Subscription> $subscriptions
     *
     * @return list<ExportedSubscriptionModel> the first subscription of each exported URL: an author feed and the
     *                                         refused RSS feed it replaces export as one
     */
    public function buildExportsWithoutRepeats(array $subscriptions): array
    {
        $byUrl = [];
        foreach ($subscriptions as $subscription) {
            $exported = $this->buildExport($subscription);
            $byUrl[$exported->feedUrl] ??= $exported;
        }

        return array_values($byUrl);
    }
}
