<?php

declare(strict_types=1);

namespace App\Service\Bluesky;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\PostEnrichment;
use App\Repository\PostEnrichmentRepository;
use App\Service\Bluesky\EntryEmbedWriter\EntryEmbedWriterInterface;
use App\Service\Bluesky\Exception\AppViewAnswerException;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Fetch\Exception\FetchException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fills queued Bluesky posts from the public AppView after a refresh, retrying on later refreshes for three days, and
 * on a client's request.
 */
final readonly class PostEnricher
{
    private const int POSTS_PER_PASS = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PostEnrichmentRepository $enrichments,
        private AppViewClient $appView,
        private EntryEmbedWriterInterface $embedWriter,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<Entry> $createdEntries already flushed
     *
     * @return list<Entry> the entries it filled, $createdEntries left out
     */
    public function enrich(Feed $feed, array $createdEntries): array
    {
        $filled = $this->guarded(fn (): array => $this->enrichQueued($feed));

        return array_values(array_filter(
            $filled,
            static fn (Entry $entry): bool => !\in_array($entry, $createdEntries, true),
        ));
    }

    /**
     * @param list<PostEnrichment> $queued AppView rows, oldest first
     *
     * @return list<Entry> the entries it filled
     */
    public function complete(array $queued): array
    {
        return $this->guarded(
            fn (): array => $this->settleAll($this->enrichments->claim($queued, $this->clock->now())),
        );
    }

    /**
     * A failure is logged, unless it closed the EntityManager: rethrown, so a refresh aborts under this feed.
     *
     * @param callable(): list<Entry> $enrichment
     *
     * @return list<Entry>
     */
    private function guarded(callable $enrichment): array
    {
        try {
            return $enrichment();
        } catch (\Exception $exception) {
            if (!$this->entityManager->isOpen()) {
                throw $exception;
            }
            $this->logger->error('Bluesky enrichment failed', ['exception' => $exception]);

            return [];
        }
    }

    /** @return list<Entry> */
    private function enrichQueued(Feed $feed): array
    {
        if (!$this->enrichments->hasAppViewPendingForFeed($feed)) {
            return [];
        }
        $now = $this->clock->now();
        $this->enrichments->deleteAppViewQueuedBefore(
            $feed,
            $now->sub(new \DateInterval(PostEnrichment::APPVIEW_GIVE_UP_AFTER)),
        );
        $queued = $this->enrichments->findClaimableAppViewForFeed($feed, $now, self::POSTS_PER_PASS);

        return $this->settleAll($this->enrichments->claim($queued, $now));
    }

    /**
     * @param list<PostEnrichment> $claimed
     *
     * @return list<Entry>
     */
    private function settleAll(array $claimed): array
    {
        if ($claimed === []) {
            return [];
        }

        $filled = [];
        $unsettled = [];
        foreach (array_chunk($claimed, AppViewClient::URIS_PER_CALL) as $chunk) {
            $settled = $this->appView->isThrottled() ? null : $this->settle($chunk);
            if ($settled === null) {
                array_push($unsettled, ...$chunk);
                continue;
            }
            array_push($filled, ...$settled);
        }
        $this->enrichments->release($unsettled);
        $this->entityManager->flush();

        return $filled;
    }

    /**
     * A post the AppView omits was deleted or hidden, so it is dequeued unfilled.
     *
     * @param list<PostEnrichment> $chunk
     *
     * @return list<Entry>|null the entries it filled; null when the AppView gave no usable answer
     */
    private function settle(array $chunk): ?array
    {
        try {
            $posts = $this->appView->posts(array_map(
                static fn (PostEnrichment $pending): string => $pending->getEntry()->getGuid(),
                $chunk,
            ));
        } catch (FetchException | AppViewAnswerException $exception) {
            $this->logger->warning('Bluesky AppView gave no usable answer', ['exception' => $exception]);

            return null;
        }

        $filled = [];
        foreach ($chunk as $pending) {
            $entry = $pending->getEntry();
            $post = $posts[$entry->getGuid()] ?? null;
            if ($post !== null && $this->filled($entry, $post)) {
                $filled[] = $entry;
            }
            $this->entityManager->remove($pending);
        }

        return $filled;
    }

    private function filled(Entry $entry, JsonNodeModel $post): bool
    {
        try {
            return $this->embedWriter->fill($entry, $post);
        } catch (\Exception $exception) {
            $this->logger->warning(
                'Bluesky post {entry} could not be filled',
                ['entry' => $entry->requireId(), 'exception' => $exception],
            );

            return false;
        }
    }
}
