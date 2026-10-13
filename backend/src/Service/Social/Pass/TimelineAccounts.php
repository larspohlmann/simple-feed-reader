<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Entity\SocialAccount;
use App\Enum\SocialAccountStatus;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\TimelineOutcome\AccountPausedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\AccountThrottledTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\ThrottledTimelineModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;

/** One refresh's verdict per account: throttled, paused, or renewed once already. */
final class TimelineAccounts
{
    /** @var array<int, SocialAccount> */
    private array $throttled = [];

    /** @var array<int, true> */
    private array $renewed = [];

    /** Why the account's feeds stay unasked this refresh, or null when nothing holds them. */
    public function heldOutcomeOf(SocialAccount $account): ?TimelineOutcomeModelInterface
    {
        if (SocialAccountStatus::NeedsReconnect === $account->getStatus()) {
            return new AccountPausedTimelineModel();
        }

        return isset($this->throttled[$account->requireId()]) ? new AccountThrottledTimelineModel() : null;
    }

    public function isRenewed(SocialAccount $account): bool
    {
        return isset($this->renewed[$account->requireId()]);
    }

    public function markRenewed(SocialAccount $account): void
    {
        $this->renewed[$account->requireId()] = true;
    }

    public function settleFailure(
        SocialAccount $account,
        SocialCredentialsRejectedException|SocialNetworkUnreachableException $failure,
    ): TimelineOutcomeModelInterface {
        if ($failure instanceof SocialNetworkThrottledException) {
            $this->throttled[$account->requireId()] = $account;

            return new ThrottledTimelineModel($failure->retryAfterSeconds);
        }
        if ($failure instanceof SocialCredentialsRejectedException) {
            $account->markNeedsReconnect();
        }

        return new FailedTimelineModel($failure);
    }
}
