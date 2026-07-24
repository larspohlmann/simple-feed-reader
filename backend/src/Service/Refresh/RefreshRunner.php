<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Repository\FeedRepository;
use App\Service\EntryIngestor;
use App\Service\EntryPruner;
use App\Service\FeedScheduler;
use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Fetch\FetchResponse;
use App\Service\Parser\Exception\FeedParseException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The one refresh implementation behind all three callers (CLI, maintenance
 * endpoint, user endpoint). Globally lock-guarded, budget-bound, flushes per
 * feed so a budget exit never loses committed work.
 *
 * The ten constructor collaborators are deliberate: the runner is the refresh
 * pipeline's composition root, and each one is a seam the tests swap
 * independently (fetcher, body parser, ingestor, scheduler, …). Bagging them
 * into a parameter object would hide that coupling, not reduce it.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final class RefreshRunner
{
    private const LOCK_NAME = 'feed-refresh';
    private const LOCK_TTL_SECONDS = 300.0;
    private const BATCH_LIMIT = 50;
    private const SAFETY_MARGIN_SECONDS = 10;
    private const COOLDOWN_MINUTES = 5;
    private const ETAG_MAX = 512;
    private const LAST_MODIFIED_MAX = 255;
    private const URL_MAX = 750;

    public function __construct(
        private readonly FeedRepository $feedRepository,
        private readonly EntityManagerInterface $em,
        private readonly FeedFetcherInterface $fetcher,
        private readonly FeedBodyParser $bodyParser,
        private readonly EntryIngestor $ingestor,
        private readonly FeedScheduler $scheduler,
        private readonly EntryPruner $pruner,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(RefreshRequest $request): RefreshReport
    {
        $lock = $this->lockFactory->createLock(self::LOCK_NAME, self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            return RefreshReport::busy();
        }

        try {
            return $this->refresh($request);
        } finally {
            $lock->release();
        }
    }

    private function refresh(RefreshRequest $request): RefreshReport
    {
        $now = $this->clock->now();
        $deadline = $now->getTimestamp() + $request->budgetSeconds;
        $cooldownCutoff = $request->force
            ? $now->modify(sprintf('-%d minutes', self::COOLDOWN_MINUTES))
            : null;

        $feeds = $this->feedRepository->findDue(
            $now,
            self::BATCH_LIMIT,
            $request->userId,
            $request->feedId,
            $request->force,
            $cooldownCutoff,
        );

        $fetched = 0;
        $notModified = 0;
        $failed = 0;
        $skippedForBudget = 0;

        foreach ($feeds as $index => $feed) {
            // The first feed is always attempted. A run that returns without
            // touching anything leaves `remaining` unchanged, and the user
            // endpoint polls until `remaining` hits 0 — so a budget at or below
            // the safety margin would spin the client forever. One feed per
            // call is slow; zero feeds per call never terminates.
            if ($index > 0 && $deadline - $this->clock->now()->getTimestamp() < self::SAFETY_MARGIN_SECONDS) {
                $skippedForBudget = \count($feeds) - $index;
                break;
            }

            $outcome = $this->refreshFeed($feed);

            if ($outcome === FeedOutcome::Aborted) {
                // The EntityManager is likely closed: no countDue, no prune.
                // This feed plus every one after it stays due for the next run.
                return RefreshReport::aborted(
                    \count($feeds),
                    $fetched,
                    $notModified,
                    $failed + 1,
                    \count($feeds) - $index,
                );
            }

            match ($outcome) {
                FeedOutcome::Fetched => $fetched++,
                FeedOutcome::NotModified => $notModified++,
                FeedOutcome::Failed => $failed++,
            };
        }

        // A single-feed scope matches on id alone — countDue ignores the
        // schedule and would keep answering 1 even after a successful refresh,
        // so a polling caller would never see `remaining` reach 0. At most one
        // feed is selected and the first is always attempted, so anything still
        // pending is exactly what the budget skipped.
        $remaining = $request->feedId !== null
            ? $skippedForBudget
            : $this->feedRepository->countDue(
                $this->clock->now(),
                $request->userId,
                $request->feedId,
                $request->force,
                $cooldownCutoff,
            );

        $pruned = $request->prune ? $this->pruner->prune() : 0;

        return RefreshReport::finished(
            \count($feeds),
            $fetched,
            $notModified,
            $failed,
            $skippedForBudget,
            $remaining,
            $pruned,
        );
    }

    private function refreshFeed(Feed $feed): FeedOutcome
    {
        try {
            return $this->fetchParseAndPersist($feed);
        } catch (UniqueConstraintViolationException | ORMException $e) {
            // A failed flush rolls back AND closes the EntityManager, so every
            // later persist/flush would throw "EntityManager is closed".
            // Stop here instead of cascading the failure across the batch.
            $this->logger->error(
                'Refresh aborted: persistence failed for {url}',
                ['url' => $feed->getUrl(), 'exception' => $e],
            );

            return FeedOutcome::Aborted;
        }
    }

    private function fetchParseAndPersist(Feed $feed): FeedOutcome
    {
        try {
            $response = $this->fetcher->fetch($feed->getUrl(), $feed->getEtag(), $feed->getLastModified());

            if ($response->notModified) {
                // A feed can be permanently moved AND answer 304 at the new
                // location; without this the redirect chain is re-walked on
                // every single refresh, forever.
                $this->applyPermanentRedirect($feed, $response);
                $this->scheduler->recordSuccess($feed, 0);
                $this->em->flush();

                return FeedOutcome::NotModified;
            }

            $body = $response->body;
            if ($body === null) {
                // Not reachable via the FetchResponse factories, but parsing an
                // empty string would silently record a bogus "successful" fetch.
                throw new FeedParseException('Fetcher returned a modified response without a body.');
            }

            $parsed = $this->bodyParser->parse($feed, $body);
            $created = $this->ingestor->ingest($feed, $parsed);

            $feed->setEtag($this->truncate($response->etag, self::ETAG_MAX));
            $feed->setLastModified($this->truncate($response->lastModified, self::LAST_MODIFIED_MAX));
            $this->applyPermanentRedirect($feed, $response);
            $this->scheduler->recordSuccess($feed, $created);
            $this->em->flush();

            return FeedOutcome::Fetched;
        } catch (FeedGoneException $e) {
            $this->scheduler->recordGone($feed, $e->getMessage());
            $this->em->flush();
            $this->logger->warning('Feed gone: {url}', ['url' => $feed->getUrl(), 'exception' => $e]);

            return FeedOutcome::Failed;
        } catch (FetchException | FeedParseException $e) {
            $this->scheduler->recordFailure($feed, $e->getMessage());
            $this->em->flush();
            $this->logger->warning('Feed refresh failed: {url}', ['url' => $feed->getUrl(), 'exception' => $e]);

            return FeedOutcome::Failed;
        }
    }

    private function applyPermanentRedirect(Feed $feed, FetchResponse $response): void
    {
        if (!$response->permanentRedirect || $response->finalUrl === $feed->getUrl()) {
            return;
        }
        // A truncated URL is a broken URL, so an over-long target is declined
        // rather than shortened; the feed keeps working at its current address.
        if (mb_strlen($response->finalUrl) > self::URL_MAX) {
            return;
        }
        // Only adopt the new URL if no other feed already claims it (unique index).
        if ($this->feedRepository->findOneBy(['url' => $response->finalUrl]) !== null) {
            return;
        }
        $feed->setUrl($response->finalUrl);
    }

    /**
     * ETag and Last-Modified are remote-controlled and go into length-limited
     * columns. SQLite ignores the limit, MySQL in strict mode rejects the row —
     * which would fail the flush, abort the run, and skip every queued feed.
     */
    private function truncate(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }
}
