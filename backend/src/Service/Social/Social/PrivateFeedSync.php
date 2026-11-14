<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\SocialAccount;
use App\Enum\PrivateFeedSyncSkip;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\NetworkCollectionsModel;
use App\Service\Social\Social\Model\PrivateFeedSyncOutcomeModel;
use App\Service\Social\Social\SocialCandidates\SocialCandidatesInterface;

/** Makes one account's lists, custom feeds and followed tags follow the network (#1666); any doubt skips the account. */
final readonly class PrivateFeedSync
{
    public function __construct(
        private SocialCandidatesInterface $networkCollections,
        private ActingAccountGuard $guard,
        private PrivateFeedSyncApplier $applier,
        private PrivateFeedSyncSkips $skips,
    ) {
    }

    public function sync(SocialAccount $account): PrivateFeedSyncOutcomeModel
    {
        try {
            return $this->synced($account);
        } catch (\Throwable $failure) {
            return $this->skips->recordFailure($account, $failure);
        }
    }

    private function synced(SocialAccount $account): PrivateFeedSyncOutcomeModel
    {
        try {
            $network = $this->guard->actAs(
                $account,
                fn (): NetworkCollectionsModel => $this->networkCollections->collections($account),
            );
        } catch (SocialAccountNeedsReconnectException | SocialCredentialsRejectedException) {
            return $this->skips->record($account, PrivateFeedSyncSkip::NeedsReconnect);
        } catch (SocialNetworkUnreachableException) {
            return $this->skips->record($account, PrivateFeedSyncSkip::Unreachable);
        }
        if (!$network->isComplete()) {
            return $this->skips->record($account, PrivateFeedSyncSkip::Incomplete);
        }

        return $this->applier->apply($account, $network);
    }
}
