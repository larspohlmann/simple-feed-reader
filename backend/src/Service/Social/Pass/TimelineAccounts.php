<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Entity\SocialAccount;
use App\Enum\SocialAccountStatus;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialSessionStaleException;
use App\Service\Social\Model\TimelineOutcome\AccountPausedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\AccountThrottledTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\ThrottledTimelineModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;

/** One refresh's verdict per account: throttled, paused, or waiting for a fresh session. */
final class TimelineAccounts
{
    /** @var array<int, SocialAccount> */
    private array $throttled = [];

    /** @var array<int, SocialAccount> */
    private array $awaitingSession = [];

    /** @var array<int, SocialAccount> */
    private array $renewed = [];

    public function mayAsk(SocialAccount $account): bool
    {
        return null === $this->heldOutcomeOf($account) && !isset($this->awaitingSession[$account->requireId()]);
    }

    /** Why the account's feeds stay unasked this refresh, or null when nothing holds them. */
    public function heldOutcomeOf(SocialAccount $account): ?TimelineOutcomeModelInterface
    {
        if (SocialAccountStatus::NeedsReconnect === $account->getStatus()) {
            return new AccountPausedTimelineModel();
        }

        return isset($this->throttled[$account->requireId()]) ? new AccountThrottledTimelineModel() : null;
    }

    /** Null while the account waits for its one renewal; a stale answer to a renewed session is a refusal. */
    public function settleStale(
        SocialAccount $account,
        SocialSessionStaleException $stale,
    ): ?TimelineOutcomeModelInterface {
        $accountId = $account->requireId();
        if (isset($this->renewed[$accountId])) {
            return $this->settleFailure(
                $account,
                new SocialCredentialsRejectedException($stale->getMessage(), previous: $stale),
            );
        }
        $this->awaitingSession[$accountId] = $account;

        return null;
    }

    /** @return array<int, SocialAccount> the accounts that waited, each counted as renewed from now on */
    public function renewSessions(): array
    {
        $accounts = $this->awaitingSession;
        $this->renewed += $accounts;
        $this->awaitingSession = [];

        return $accounts;
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
