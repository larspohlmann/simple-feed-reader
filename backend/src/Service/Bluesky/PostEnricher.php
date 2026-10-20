<?php

declare(strict_types=1);

namespace App\Service\Bluesky;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Repository\ShareEnrichmentRepository;
use App\Service\Bluesky\EntryEmbedWriter\EntryEmbedWriterInterface;
use App\Service\Bluesky\Exception\AppViewAnswerException;
use App\Service\Bluesky\Model\FillOutcome;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Support\BlueskyPostView;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Search\EntryIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class PostEnricher
{
    private const int POSTS_PER_PASS = 100;

    private const int GIVEN_UP_PER_PASS = 200;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ShareEnrichmentRepository $enrichments,
        private AppViewClient $appView,
        private EntryEmbedWriterInterface $embedWriter,
        private EntryIndexer $indexer,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function enrich(Feed $feed): void
    {
        $this->guarded(fn () => $this->enrichQueued($feed));
    }

    /** @param list<ShareEnrichment> $queued AppView rows, oldest first */
    public function complete(array $queued): void
    {
        $this->guarded(fn () => $this->claimAndSettle($queued, $this->clock->now()));
    }

    /**
     * A failure is logged, unless it closed the EntityManager: rethrown, so a refresh aborts under this feed.
     *
     * @param callable(): void $enrichment
     */
    private function guarded(callable $enrichment): void
    {
        try {
            $enrichment();
        } catch (\Exception $exception) {
            if (!$this->entityManager->isOpen()) {
                throw $exception;
            }
            $this->logger->error('Bluesky enrichment failed', ['exception' => $exception]);
        }
    }

    private function enrichQueued(Feed $feed): void
    {
        if (!$this->enrichments->hasPendingForFeed($feed, ShareEnrichmentKind::AppView)) {
            return;
        }
        $now = $this->clock->now();
        $this->drop(
            $this->enrichments->findClaimableGivenUpForFeed(
                $feed,
                ShareEnrichmentKind::AppView,
                $now,
                self::GIVEN_UP_PER_PASS,
            ),
            $now,
        );

        $this->claimAndSettle(
            $this->enrichments->findClaimableLiveForFeed(
                $feed,
                ShareEnrichmentKind::AppView,
                $now,
                self::POSTS_PER_PASS,
            ),
            $now,
        );
    }

    /** @param list<ShareEnrichment> $givenUp */
    private function drop(array $givenUp, \DateTimeImmutable $claimedAt): void
    {
        $claimed = $this->enrichments->claim($givenUp, $claimedAt);
        if ($claimed === []) {
            return;
        }
        foreach ($claimed as $row) {
            $this->entityManager->remove($row);
        }
        $this->entityManager->flush();
    }

    /**
     * A chunk settles only the rows the claim still holds: a slow pass may have outlived the lease on later ones. A row
     * handed to the link-card or self-thread pass is released only once its new kind is flushed, or an AppView pass
     * would refill it.
     *
     * @param list<ShareEnrichment> $queued
     */
    private function claimAndSettle(array $queued, \DateTimeImmutable $claimedAt): void
    {
        $claimed = $this->enrichments->claim($queued, $claimedAt);
        if ($claimed === []) {
            return;
        }

        $filled = [];
        $unsettled = [];
        foreach (array_chunk($claimed, AppViewClient::URIS_PER_CALL) as $chunk) {
            $held = $this->enrichments->stillHeld($chunk, $claimedAt);
            if ($held === []) {
                continue;
            }
            if ($this->appView->isThrottled()) {
                array_push($unsettled, ...$held);
                continue;
            }
            try {
                array_push($filled, ...$this->settle($held));
            } catch (FetchException | AppViewAnswerException $exception) {
                $this->logger->warning('Bluesky AppView gave no usable answer', ['exception' => $exception]);
                array_push($unsettled, ...$held);
            }
        }
        $this->entityManager->flush();
        $this->enrichments->release([...$unsettled, ...self::handedOn($claimed)], $claimedAt);
        $this->indexer->index($filled);
    }

    /**
     * @param list<ShareEnrichment> $claimed
     *
     * @return list<ShareEnrichment>
     */
    private static function handedOn(array $claimed): array
    {
        return array_values(array_filter(
            $claimed,
            static fn (ShareEnrichment $row): bool => $row->getKind() !== ShareEnrichmentKind::AppView,
        ));
    }

    /**
     * A post the AppView omits was deleted or hidden, so it is dequeued unfilled. One whose link still needs its card
     * stays queued for the link-card pass, and one with replies for the self-thread pass.
     *
     * @param list<ShareEnrichment> $chunk
     *
     * @return list<Entry> the entries it filled
     *
     * @throws FetchException|AppViewAnswerException when the AppView gave no usable answer
     */
    private function settle(array $chunk): array
    {
        $posts = $this->appView->posts(array_map(
            static fn (ShareEnrichment $pending): string => $pending->getEntry()->getGuid(),
            $chunk,
        ));

        $filled = [];
        foreach ($chunk as $pending) {
            $entry = $pending->getEntry();
            $post = $posts[$entry->getGuid()] ?? null;
            $outcome = $post === null ? FillOutcome::Unfilled : $this->filled($entry, $post);
            if ($outcome !== FillOutcome::Unfilled) {
                $filled[] = $entry;
            }
            if ($outcome === FillOutcome::Unfilled) {
                $entry->setShareKind(null);
            }
            if ($post !== null && BlueskyPostView::hasReplies($post)) {
                $pending->awaitSelfThread();
                continue;
            }
            if ($outcome === FillOutcome::AwaitingLinkCards) {
                $pending->awaitLinkCards();
                continue;
            }
            $this->entityManager->remove($pending);
        }

        return $filled;
    }

    private function filled(Entry $entry, JsonNodeModel $post): FillOutcome
    {
        try {
            return $this->embedWriter->fill($entry, $post);
        } catch (\Exception $exception) {
            $this->logger->warning(
                'Bluesky post {entry} could not be filled',
                ['entry' => $entry->requireId(), 'exception' => $exception],
            );

            return FillOutcome::Unfilled;
        }
    }
}
