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

/** Fills queued Bluesky posts from the public AppView after a refresh, retrying on later refreshes for three days. */
final readonly class PostEnricher
{
    private const int POSTS_PER_PASS = 100;
    private const string GIVE_UP_AFTER = 'P3D';

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
     * A failure is logged and leaves the refresh as it was, unless it closed the EntityManager: that one is rethrown,
     * so the refresh aborts under this feed rather than the next.
     *
     * @param list<Entry> $createdEntries already flushed
     *
     * @return list<Entry> the entries it filled, $createdEntries left out
     */
    public function enrich(Feed $feed, array $createdEntries): array
    {
        try {
            return $this->enrichQueued($feed, $createdEntries);
        } catch (\Exception $exception) {
            if (!$this->entityManager->isOpen()) {
                throw $exception;
            }
            $this->logger->error(
                'Bluesky enrichment failed for {url}',
                ['url' => $feed->getUrl(), 'exception' => $exception],
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
            $this->clock->now()->sub(new \DateInterval(self::GIVE_UP_AFTER)),
        );
        $pending = $this->pendingPosts->findOldestForFeed($feed, self::POSTS_PER_PASS);

        $filled = [];
        foreach (array_chunk($pending, AppViewClient::URIS_PER_CALL) as $chunk) {
            if ($this->appView->isThrottled()) {
                break;
            }
            array_push($filled, ...$this->settle($feed, $chunk));
        }
        $this->entityManager->flush();

        return array_values(array_filter(
            $filled,
            static fn (Entry $entry): bool => !\in_array($entry, $createdEntries, true),
        ));
    }

    /**
     * Fills what the AppView answered and dequeues the whole chunk; a post it omits is deleted or hidden.
     *
     * @param list<PendingPostEnrichment> $chunk
     *
     * @return list<Entry>
     */
    private function settle(Feed $feed, array $chunk): array
    {
        try {
            $posts = $this->appView->posts(array_map(
                static fn (PendingPostEnrichment $pending): string => $pending->getEntry()->getGuid(),
                $chunk,
            ));
        } catch (FetchException | AppViewAnswerException $exception) {
            $this->logger->warning(
                'Bluesky AppView gave no usable answer for {url}',
                ['url' => $feed->getUrl(), 'exception' => $exception],
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
