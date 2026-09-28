<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\FeedRepository;
use App\Service\FeedScheduler;
use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FetchOutcome;
use App\Service\Fetch\FetchResponse;
use App\Service\Ingest\EntryIngestor;
use App\Service\Ingest\FeedIngestContext;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Search\EntryIndexer;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Psr\Log\LoggerInterface;

/** Stores one feed's fetch outcome and flushes it, so a budget exit never loses what an earlier feed committed. */
final readonly class FeedOutcomePersister
{
    private const int URL_MAX = 750;

    public function __construct(
        private EntityManagerInterface $em,
        private FeedRepository $feedRepository,
        private FeedBodyParser $bodyParser,
        private EntryIngestor $ingestor,
        private FeedScheduler $scheduler,
        private EntryIndexer $indexer,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws \DateMalformedStringException */
    public function persist(Feed $feed, FetchOutcome $outcome, \DateTimeImmutable $now): FeedRefreshResult
    {
        // Read before recordSuccess() stamps the new lastSuccessfulFetchAt (#384).
        $context = new FeedIngestContext($now, $feed->getLastSuccessfulFetchAt());

        try {
            return $this->record($feed, $outcome, $context);
        } catch (UniqueConstraintViolationException | ForeignKeyConstraintViolationException | ORMException $e) {
            // A failed flush closes the EntityManager, so the run must stop here. The FK case is a feed whose
            // last subscriber left mid-run and whose row was reclaimed under the fetch (#246).
            $this->logger->error(
                'Refresh aborted: persistence failed for {url}',
                ['url' => $feed->getUrl(), 'exception' => $e],
            );

            return FeedRefreshResult::of(FeedOutcome::Aborted);
        }
    }

    private function record(Feed $feed, FetchOutcome $outcome, FeedIngestContext $context): FeedRefreshResult
    {
        try {
            $response = $outcome->responseOrThrow();
            if ($response->notModified) {
                return $this->storeNotModified($feed, $response);
            }

            $parsed = $this->bodyParser->parse($feed, $response->modifiedBody());
            $createdEntries = $this->ingestor->ingest($feed, $parsed, $context);
            // A backfilled image is not new content, so it stays out of the count (#148).
            $this->ingestor->fillMissingImages($feed, $parsed);

            return $this->storeFetched($feed, $response, $createdEntries);
        } catch (FeedThrottledException $e) {
            return $this->recordThrottled($feed, $e);
        } catch (FeedGoneException $e) {
            return $this->recordGone($feed, $e);
        } catch (FetchException | FeedParseException $e) {
            return $this->recordFailure($feed, $e);
        }
    }

    /** @throws \DateMalformedStringException */
    private function storeNotModified(Feed $feed, FetchResponse $response): FeedRefreshResult
    {
        // A moved feed can answer 304 at its new address; without this the redirect chain is re-walked every time.
        $this->applyPermanentRedirect($feed, $response);
        $this->scheduler->recordSuccess($feed, 0);
        $this->em->flush();

        return FeedRefreshResult::of(FeedOutcome::NotModified);
    }

    /**
     * @param list<Entry> $createdEntries
     *
     * @throws \DateMalformedStringException
     */
    private function storeFetched(Feed $feed, FetchResponse $response, array $createdEntries): FeedRefreshResult
    {
        $feed->recordCacheValidators($response->etag, $response->lastModified);
        $this->applyPermanentRedirect($feed, $response);
        $this->scheduler->recordSuccess($feed, \count($createdEntries));
        $this->em->flush();
        // Only the flush assigns ids, so indexing has to follow it (#432).
        $this->indexer->index($createdEntries);

        return FeedRefreshResult::fetched(\count($createdEntries));
    }

    /** @throws \DateMalformedStringException */
    private function recordThrottled(Feed $feed, FeedThrottledException $throttled): FeedRefreshResult
    {
        $this->scheduler->recordThrottled($feed, $throttled->retryAfterSeconds);
        $this->em->flush();
        $this->logger->info('Feed rate limited: {url}', ['url' => $feed->getUrl()]);

        return FeedRefreshResult::of(FeedOutcome::Throttled);
    }

    private function recordGone(Feed $feed, FeedGoneException $gone): FeedRefreshResult
    {
        $this->scheduler->recordGone($feed, $gone->getMessage());
        $this->em->flush();
        $this->logger->warning('Feed gone: {url}', ['url' => $feed->getUrl(), 'exception' => $gone]);

        return FeedRefreshResult::of(FeedOutcome::Failed);
    }

    /** @throws \DateMalformedStringException */
    private function recordFailure(Feed $feed, FetchException|FeedParseException $failure): FeedRefreshResult
    {
        $this->scheduler->recordFailure($feed, $failure->getMessage());
        $this->em->flush();
        $this->logger->warning('Feed refresh failed: {url}', ['url' => $feed->getUrl(), 'exception' => $failure]);

        return FeedRefreshResult::of(FeedOutcome::Failed);
    }

    private function applyPermanentRedirect(Feed $feed, FetchResponse $response): void
    {
        if (!$response->permanentRedirect || $response->finalUrl === $feed->getUrl()) {
            return;
        }
        // A truncated URL is a broken URL, so an over-long target is declined rather than shortened.
        if (mb_strlen($response->finalUrl) > self::URL_MAX) {
            return;
        }
        // The url column is unique: a target another feed already claims leaves this feed where it is.
        if ($this->feedRepository->findOneBy(['url' => $response->finalUrl]) !== null) {
            return;
        }
        $feed->setUrl($response->finalUrl);
    }
}
