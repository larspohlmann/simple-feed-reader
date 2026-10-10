<?php

declare(strict_types=1);

namespace App\Service\Bluesky;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\PendingPostEnrichment;
use App\Repository\PendingPostEnrichmentRepository;
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
    private const string ON_REQUEST = 'a completion request';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PendingPostEnrichmentRepository $pendingPosts,
        private PendingPostQueue $queue,
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
        return $this->guarded($feed->getUrl(), fn (): array => $this->enrichQueued($feed, $createdEntries));
    }

    /**
     * Fills those of these entries that are queued now, as a refresh would, without waiting for one.
     *
     * @param list<Entry> $entries
     *
     * @return list<Entry> the entries it filled
     */
    public function enrichEntries(array $entries): array
    {
        return $this->guarded(self::ON_REQUEST, fn (): array => $this->enrichListed($entries));
    }

    /**
     * A failure is logged, unless it closed the EntityManager: rethrown, so a refresh aborts under this feed.
     *
     * @param callable(): list<Entry> $enrichment
     *
     * @return list<Entry>
     */
    private function guarded(string $source, callable $enrichment): array
    {
        try {
            return $enrichment();
        } catch (\Exception $exception) {
            if (!$this->entityManager->isOpen()) {
                throw $exception;
            }
            $this->logger->error(
                'Bluesky enrichment failed for {source}',
                ['source' => $source, 'exception' => $exception],
            );

            return [];
        }
    }

    /**
     * @param list<Entry> $createdEntries
     *
     * @return list<Entry>
     */
    private function enrichQueued(Feed $feed, array $createdEntries): array
    {
        if ($this->queue->queue($createdEntries) > 0) {
            $this->entityManager->flush();
        } elseif (!$this->pendingPosts->hasPendingForFeed($feed)) {
            return [];
        }
        $this->pendingPosts->deleteQueuedBefore(
            $feed,
            $this->clock->now()->sub(new \DateInterval(PendingPostEnrichment::GIVE_UP_AFTER)),
        );
        $filled = $this->settleAll(
            $this->pendingPosts->findOldestForFeed($feed, self::POSTS_PER_PASS),
            $feed->getUrl(),
        );

        return array_values(array_filter(
            $filled,
            static fn (Entry $entry): bool => !\in_array($entry, $createdEntries, true),
        ));
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<Entry>
     */
    private function enrichListed(array $entries): array
    {
        $pending = $this->pendingPosts->findForEntries($entries);

        return $pending === [] ? [] : $this->settleAll($pending, self::ON_REQUEST);
    }

    /**
     * @param list<PendingPostEnrichment> $pending
     *
     * @return list<Entry>
     */
    private function settleAll(array $pending, string $source): array
    {
        $filled = [];
        foreach (array_chunk($pending, AppViewClient::URIS_PER_CALL) as $chunk) {
            if ($this->appView->isThrottled()) {
                break;
            }
            array_push($filled, ...$this->settle($chunk, $source));
        }
        $this->entityManager->flush();

        return $filled;
    }

    /**
     * A post the AppView omits was deleted or hidden, so it is dequeued unfilled.
     *
     * @param list<PendingPostEnrichment> $chunk
     *
     * @return list<Entry>
     */
    private function settle(array $chunk, string $source): array
    {
        try {
            $posts = $this->appView->posts(array_map(
                static fn (PendingPostEnrichment $pending): string => $pending->getEntry()->getGuid(),
                $chunk,
            ));
        } catch (FetchException | AppViewAnswerException $exception) {
            $this->logger->warning(
                'Bluesky AppView gave no usable answer for {source}',
                ['source' => $source, 'exception' => $exception],
            );

            return [];
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
