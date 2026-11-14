<?php

declare(strict_types=1);

namespace App\Service\Sourcing\Preview;

use App\Entity\User;
use App\Service\Sourcing\Discovery\AccountOnlyFeed\AccountOnlyFeedInterface;
use App\Service\Sourcing\Discovery\Exception\SiteThrottledException;
use App\Service\Sourcing\Discovery\FeedDiscovery\FeedDiscoveryInterface;
use App\Service\Sourcing\Discovery\Model\DiscoveredFeedModel;
use App\Service\Sourcing\Discovery\Model\FeedCandidateModel;
use App\Service\Sourcing\Discovery\Model\FeedDiscoveryResultModel;
use App\Service\Sourcing\Discovery\Model\ScrapeFailureReason;
use App\Service\Sourcing\Discovery\ScrapeFallbackPolicy;
use App\Service\Sourcing\Preview\Factory\FeedPreviewFactory;
use App\Service\Sourcing\Preview\Model\PreviewedCandidateModel;
use App\Service\Sourcing\Preview\Model\PreviewedDiscoveryModel;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class PreviewedDiscovery
{
    /** @param iterable<AccountOnlyFeedInterface> $accountOnlyFeeds */
    public function __construct(
        private FeedDiscoveryInterface $discovery,
        private ScrapeFallbackPolicy $scrapeFallbackPolicy,
        private FeedPreviewFactory $previewFactory,
        #[AutowireIterator('app.account_only_feed')]
        private iterable $accountOnlyFeeds,
    ) {
    }

    public function discover(User $user, string $url): PreviewedDiscoveryModel
    {
        $accountOnly = $this->accountOnlyCandidate($user, $url);
        if (null !== $accountOnly) {
            return new PreviewedDiscoveryModel([new PreviewedCandidateModel($accountOnly)]);
        }

        $result = $this->discovered($user, $url);
        $feed = $result->feed;
        if (null !== $feed) {
            return new PreviewedDiscoveryModel([$this->previewed($feed)]);
        }

        return new PreviewedDiscoveryModel(
            array_map(
                static fn (FeedCandidateModel $candidate): PreviewedCandidateModel => new PreviewedCandidateModel(
                    $candidate,
                ),
                $result->candidates,
            ),
            $result->scrapeFailureReason,
        );
    }

    private function discovered(User $user, string $url): FeedDiscoveryResultModel
    {
        try {
            return $this->discovery->discover($url, $this->scrapeFallbackPolicy->forUser($user));
        } catch (SiteThrottledException) {
            return FeedDiscoveryResultModel::scrapeFailed(ScrapeFailureReason::Throttled);
        }
    }

    private function accountOnlyCandidate(User $user, string $url): ?FeedCandidateModel
    {
        foreach ($this->accountOnlyFeeds as $accountOnlyFeed) {
            $candidate = $accountOnlyFeed->candidateFor($user, $url);
            if (null !== $candidate) {
                return $candidate;
            }
        }

        return null;
    }

    private function previewed(DiscoveredFeedModel $feed): PreviewedCandidateModel
    {
        return new PreviewedCandidateModel(
            FeedCandidateModel::of($feed),
            $this->previewFactory->fromDocument($feed->document),
        );
    }
}
