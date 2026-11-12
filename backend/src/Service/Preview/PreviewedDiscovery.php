<?php

declare(strict_types=1);

namespace App\Service\Preview;

use App\Entity\User;
use App\Service\Discovery\FeedDiscovery\FeedDiscoveryInterface;
use App\Service\Discovery\Model\DiscoveredFeedModel;
use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Discovery\ScrapeFallbackPolicy;
use App\Service\Preview\Factory\FeedPreviewFactory;
use App\Service\Preview\Model\PreviewedCandidateModel;
use App\Service\Preview\Model\PreviewedDiscoveryModel;

final readonly class PreviewedDiscovery
{
    public function __construct(
        private FeedDiscoveryInterface $discovery,
        private ScrapeFallbackPolicy $scrapeFallbackPolicy,
        private FeedPreviewFactory $previewFactory,
    ) {
    }

    public function discover(User $user, string $url): PreviewedDiscoveryModel
    {
        $result = $this->discovery->discover($url, $this->scrapeFallbackPolicy->forUser($user));
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

    private function previewed(DiscoveredFeedModel $feed): PreviewedCandidateModel
    {
        return new PreviewedCandidateModel(
            FeedCandidateModel::of($feed),
            $this->previewFactory->fromDocument($feed->document),
        );
    }
}
