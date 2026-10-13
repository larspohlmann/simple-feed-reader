<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Service\Fetch\DispatchGate\DispatchGateInterface;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialSessionStaleException;
use App\Service\Social\Model\TimelineOutcomeModel;
use App\Service\Social\TimelineSources;

/**
 * One refresh's private-feed reads: a ticket per feed, a gate that keeps a held account's waiting tickets off the
 * wire, and each answer settled into an outcome. A feed settled without a request waits in `unasked` until drained.
 */
final class TimelineRound implements DispatchGateInterface
{
    /** @var array<int, TimelineOutcomeModel> */
    private array $unasked = [];

    /** @var list<int> */
    private array $staleFeedIds = [];

    /** @param array<int, SocialFeed> $socialFeedsByFeedId */
    public function __construct(
        private readonly array $socialFeedsByFeedId,
        private readonly TimelineSources $sources,
        private readonly TimelineAccounts $accounts,
    ) {
    }

    /**
     * @param iterable<int, Feed> $feeds
     *
     * @return \Generator<int, RequestTicketModel>
     */
    public function tickets(iterable $feeds): \Generator
    {
        foreach ($feeds as $feedId => $feed) {
            yield from $this->ticketOf($feedId);
        }
    }

    /**
     * Drops each stale account's session for a fresh one, then sends again the feeds it held back; empty when no
     * session went stale.
     *
     * @return \Generator<int, RequestTicketModel>
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

    /** The feed's outcome, or null while it waits for the stale retry wave. */
    public function settle(int $feedId, FetchOutcomeModel $outcome): ?TimelineOutcomeModel
    {
        $socialFeed = $this->socialFeedOf($feedId);
        $account = $socialFeed->getSocialAccount();
        if ($outcome->isWithheld()) {
            return $this->holdOrQueueForRenewal($feedId, $account);
        }

        try {
            $page = $this->sources->of($account)->pageOf($socialFeed, $outcome);
        } catch (SocialSessionStaleException $stale) {
            return $this->settleStale($feedId, $account, $stale);
        } catch (SocialCredentialsRejectedException | SocialNetworkUnreachableException $failure) {
            return $this->accounts->settleFailure($account, $failure);
        }
        $socialFeed->recordNewestSeen($page->newestId);

        return TimelineOutcomeModel::fetched($page);
    }

    /** @return \Generator<int, TimelineOutcomeModel> */
    public function drainUnasked(): \Generator
    {
        $unasked = $this->unasked;
        $this->unasked = [];

        yield from $unasked;
    }

    /** @return \Generator<int, RequestTicketModel> */
    private function ticketOf(int $feedId): \Generator
    {
        $socialFeed = $this->socialFeedsByFeedId[$feedId] ?? null;
        if (null === $socialFeed) {
            $this->unasked[$feedId] = TimelineOutcomeModel::failed(new PrivateFeedOrphanedException());

            return;
        }
        $account = $socialFeed->getSocialAccount();
        if (!$this->accounts->mayAsk($account)) {
            $held = $this->holdOrQueueForRenewal($feedId, $account);
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

        yield $feedId => RequestTicketModel::api($request, sprintf('social-account-%d', $account->requireId()));
    }

    /** The account's held outcome, or null once the feed is queued for the stale retry wave. */
    private function holdOrQueueForRenewal(int $feedId, SocialAccount $account): ?TimelineOutcomeModel
    {
        $held = $this->accounts->heldOutcomeOf($account);
        if (null === $held) {
            $this->staleFeedIds[] = $feedId;
        }

        return $held;
    }

    private function settleStale(
        int $feedId,
        SocialAccount $account,
        SocialSessionStaleException $stale,
    ): ?TimelineOutcomeModel {
        $refused = $this->accounts->settleStale($account, $stale);
        if (null === $refused) {
            $this->staleFeedIds[] = $feedId;
        }

        return $refused;
    }

    private function socialFeedOf(int|string $feedId): SocialFeed
    {
        return $this->socialFeedsByFeedId[$feedId]
            ?? throw new \LogicException(sprintf('Feed %s was never ticketed in this round.', $feedId));
    }
}
