<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Model\SettlementModel;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialSessionStaleException;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FetchedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use App\Service\Social\Model\TimelineSlotsModel;
use App\Service\Social\TimelineSources;

/**
 * One refresh's private-feed reads: a ticket per feed and a follow-up per further request, gated per held account,
 * each answer settled into an outcome or a follow-up; a stale session is renewed once per account.
 */
final class TimelineRound
{
    /** @var array<int|string, TimelineOutcomeModelInterface> */
    private array $unasked = [];

    /** @var array<int|string, true> feeds whose current ticket was built after their account's renewal */
    private array $onRenewedSession = [];

    /** @var array<int|string, TimelineRead> */
    private array $reads = [];

    private readonly TimelineAccounts $accounts;

    /** @param array<int, SocialFeed> $socialFeedsByFeedId each with its account loaded */
    public function __construct(
        private readonly TimelineSources $sources,
        private readonly TimelineSlotsModel $slots,
        private readonly array $socialFeedsByFeedId,
    ) {
        $this->accounts = new TimelineAccounts();
    }

    /** @return \Generator<int|string, RequestTicketModel> none when the feed settles without a request */
    public function ticketFor(int|string $feedId): \Generator
    {
        try {
            $socialFeed = $this->socialFeedOf($feedId);
        } catch (PrivateFeedOrphanedException $orphaned) {
            $this->unasked[$feedId] = new FailedTimelineModel($orphaned);

            return;
        }

        $settlement = $this->ticketOf($feedId, $socialFeed);
        if ($settlement->isRequest()) {
            yield $feedId => $settlement->requestTicket();

            return;
        }

        $this->unasked[$feedId] = $settlement->settledOutcome();
    }

    public function admits(int|string $key): bool
    {
        $account = $this->socialFeedOf($key)->getSocialAccount();
        if (null !== $this->accounts->heldOutcomeOf($account)) {
            return false;
        }

        // A ticket built before its account's renewal carries the dropped session: withheld, it is built again.
        return !$this->accounts->isRenewed($account) || isset($this->onRenewedSession[$key]);
    }

    /** @return SettlementModel<TimelineOutcomeModelInterface> */
    public function settle(int|string $feedId, FetchOutcomeModel $outcome): SettlementModel
    {
        $socialFeed = $this->socialFeedOf($feedId);
        $account = $socialFeed->getSocialAccount();
        if ($outcome->isWithheld()) {
            return $this->ticketOf($feedId, $socialFeed);
        }

        try {
            return $this->settleAnswer($feedId, $socialFeed, $account, $outcome);
        } catch (SocialSessionStaleException $stale) {
            return $this->settleStale($feedId, $socialFeed, $stale);
        } catch (SocialCredentialsRejectedException | SocialNetworkUnreachableException $failure) {
            return $this->failed($feedId, $account, $failure);
        }
    }

    /** @return \Generator<int|string, TimelineOutcomeModelInterface> */
    public function drainUnasked(): \Generator
    {
        $unasked = $this->unasked;
        $this->unasked = [];

        yield from $unasked;
    }

    /**
     * The feed's next request while it has one, else its page from every answer.
     *
     * @return SettlementModel<TimelineOutcomeModelInterface>
     *
     * @throws SocialSessionStaleException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    private function settleAnswer(
        int|string $feedId,
        SocialFeed $socialFeed,
        SocialAccount $account,
        FetchOutcomeModel $outcome,
    ): SettlementModel {
        $source = $this->sources->of($account);
        $read = $this->reads[$feedId] ?? throw new \LogicException('A feed is settled before it was asked.');
        $read->accept($source->accepted($socialFeed, $outcome));
        if (!$read->isComplete()) {
            return $this->requestOf($feedId, $account, $read);
        }

        unset($this->reads[$feedId]);
        $page = $source->pageOf($socialFeed, $read->accepted());
        $socialFeed->recordNewestSeen($page->newestId);

        return SettlementModel::outcome(new FetchedTimelineModel($page));
    }

    /**
     * A stale answer on the renewed session is a refusal; one on the dropped session renews it once and asks again.
     *
     * @return SettlementModel<TimelineOutcomeModelInterface>
     */
    private function settleStale(
        int|string $feedId,
        SocialFeed $socialFeed,
        SocialSessionStaleException $stale,
    ): SettlementModel {
        $account = $socialFeed->getSocialAccount();
        if (isset($this->onRenewedSession[$feedId])) {
            return $this->failed(
                $feedId,
                $account,
                new SocialCredentialsRejectedException($stale->getMessage(), previous: $stale),
            );
        }
        $this->renewOnce($account);

        return $this->ticketOf($feedId, $socialFeed);
    }

    /** @return SettlementModel<TimelineOutcomeModelInterface> */
    private function failed(
        int|string $feedId,
        SocialAccount $account,
        SocialCredentialsRejectedException|SocialNetworkUnreachableException $failure,
    ): SettlementModel {
        unset($this->reads[$feedId]);

        return SettlementModel::outcome($this->accounts->settleFailure($account, $failure));
    }

    private function renewOnce(SocialAccount $account): void
    {
        if ($this->accounts->isRenewed($account)) {
            return;
        }
        $this->accounts->markRenewed($account);
        $this->sources->of($account)->dropSession($account);
    }

    /** @return SettlementModel<TimelineOutcomeModelInterface> the feed's ticket, or what it settles at without one */
    private function ticketOf(int|string $feedId, SocialFeed $socialFeed): SettlementModel
    {
        $account = $socialFeed->getSocialAccount();
        $held = $this->accounts->heldOutcomeOf($account);
        if (null !== $held) {
            return SettlementModel::outcome($held);
        }

        try {
            $read = new TimelineRead($this->sources->of($account)->requests($socialFeed));
        } catch (SocialCredentialsRejectedException | SocialNetworkUnreachableException $failure) {
            return SettlementModel::outcome($this->accounts->settleFailure($account, $failure));
        }
        $this->reads[$feedId] = $read;

        return $this->requestOf($feedId, $account, $read);
    }

    /** @return SettlementModel<TimelineOutcomeModelInterface> */
    private function requestOf(int|string $feedId, SocialAccount $account, TimelineRead $read): SettlementModel
    {
        if ($this->accounts->isRenewed($account)) {
            $this->onRenewedSession[$feedId] = true;
        }

        return SettlementModel::request($this->slots->ticketFor($account, $read->current()));
    }

    /** @throws PrivateFeedOrphanedException when the private feed has lost its social feed */
    private function socialFeedOf(int|string $feedId): SocialFeed
    {
        return $this->socialFeedsByFeedId[$feedId] ?? throw new PrivateFeedOrphanedException();
    }
}
