<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Entity\SocialFeed;
use App\Service\Fetch\DispatchGate\DispatchGateInterface;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialSessionStaleException;
use App\Service\Social\Model\TimelineOutcome\FetchedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use App\Service\Social\Model\TimelineSlotsModel;
use App\Service\Social\TimelineSources;

/**
 * One refresh's private-feed reads: a ticket per feed, a gate that keeps a held account's waiting tickets off the
 * wire, and each answer settled into an outcome. A feed settled without a request waits in `unasked` until drained.
 */
final class TimelineRound implements DispatchGateInterface
{
    /** @var array<int|string, TimelineOutcomeModelInterface> */
    private array $unasked = [];

    /** @var list<int|string> */
    private array $staleFeedIds = [];

    /** @var array<int, SocialFeed> every feed ticketed so far */
    private array $socialFeedsByFeedId = [];

    public function __construct(
        private readonly TimelineSources $sources,
        private readonly TimelineAccounts $accounts,
        private readonly TimelineSlotsModel $slots,
    ) {
    }

    /** @return \Generator<int|string, RequestTicketModel> none when the feed settles without a request */
    public function ticketFor(int $feedId, SocialFeed $socialFeed): \Generator
    {
        $this->socialFeedsByFeedId[$feedId] = $socialFeed;

        yield from $this->ticketOf($feedId);
    }

    /**
     * Drops each stale account's session for a fresh one, then sends again the feeds it held back; empty when no
     * session went stale.
     *
     * @return \Generator<int|string, RequestTicketModel>
     */
    public function startRenewalWave(): \Generator
    {
        $feedIds = $this->staleFeedIds;
        $this->staleFeedIds = [];
        foreach ($this->accounts->renewSessions() as $account) {
            $this->sources->of($account)->dropSession($account);
        }
        foreach ($feedIds as $feedId) {
            yield from $this->ticketOf($feedId);
        }
    }

    public function admits(int|string $key): bool
    {
        return $this->accounts->mayAsk($this->socialFeedOf($key)->getSocialAccount());
    }

    /** The feed's outcome, or null while it waits for the renewal wave. */
    public function settle(int|string $feedId, FetchOutcomeModel $outcome): ?TimelineOutcomeModelInterface
    {
        $socialFeed = $this->socialFeedOf($feedId);
        $account = $socialFeed->getSocialAccount();
        if ($outcome->isWithheld()) {
            return $this->settledOrQueued($feedId, $this->accounts->heldOutcomeOf($account));
        }

        try {
            $page = $this->sources->of($account)->pageOf($socialFeed, $outcome);
        } catch (SocialSessionStaleException $stale) {
            return $this->settledOrQueued($feedId, $this->accounts->settleStale($account, $stale));
        } catch (SocialCredentialsRejectedException | SocialNetworkUnreachableException $failure) {
            return $this->accounts->settleFailure($account, $failure);
        }
        $socialFeed->recordNewestSeen($page->newestId);

        return new FetchedTimelineModel($page);
    }

    /** @return \Generator<int|string, TimelineOutcomeModelInterface> */
    public function drainUnasked(): \Generator
    {
        $unasked = $this->unasked;
        $this->unasked = [];

        yield from $unasked;
    }

    /** @return \Generator<int|string, RequestTicketModel> */
    private function ticketOf(int|string $feedId): \Generator
    {
        $socialFeed = $this->socialFeedOf($feedId);
        $account = $socialFeed->getSocialAccount();
        if (!$this->accounts->mayAsk($account)) {
            $held = $this->settledOrQueued($feedId, $this->accounts->heldOutcomeOf($account));
            if (null !== $held) {
                $this->unasked[$feedId] = $held;
            }

            return;
        }

        try {
            $request = $this->sources->of($account)->request($socialFeed);
        } catch (SocialCredentialsRejectedException | SocialNetworkUnreachableException $failure) {
            $this->unasked[$feedId] = $this->accounts->settleFailure($account, $failure);

            return;
        }

        yield $feedId => $this->slots->ticketFor($account, $request);
    }

    /** The settled outcome, or null once the feed is queued for the renewal wave. */
    private function settledOrQueued(
        int|string $feedId,
        ?TimelineOutcomeModelInterface $settled,
    ): ?TimelineOutcomeModelInterface {
        if (null === $settled) {
            $this->staleFeedIds[] = $feedId;
        }

        return $settled;
    }

    private function socialFeedOf(int|string $feedId): SocialFeed
    {
        return $this->socialFeedsByFeedId[$feedId]
            ?? throw new \LogicException(sprintf('Feed %s was never ticketed in this round.', $feedId));
    }
}
