<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\SocialAccountRepository;
use App\Service\Discovery\AccountOnlyFeed\AccountOnlyFeedInterface;
use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Discovery\Model\NoPreviewReason;

final readonly class HiddenProfileAccountOnlyFeed implements AccountOnlyFeedInterface
{
    public function __construct(
        private TolerantHiddenProfiles $hiddenProfiles,
        private SocialAccountRepository $socialAccounts,
    ) {
    }

    public function candidateFor(User $user, string $url): ?FeedCandidateModel
    {
        $profile = $this->hiddenProfiles->hiddenAt(
            $url,
            'Discover could not tell whether a Bluesky profile is hidden; discovery decides.',
        );
        if (null === $profile) {
            return null;
        }

        return new FeedCandidateModel($url, $profile->name, SourceFormat::BLUESKY_API, $this->reasonFor($user));
    }

    private function reasonFor(User $user): NoPreviewReason
    {
        return null === $this->socialAccounts->findReadyActingOnNetwork($user, SocialNetwork::Bluesky)
            ? NoPreviewReason::ConnectBlueskyAccount
            : NoPreviewReason::HiddenProfile;
    }
}
