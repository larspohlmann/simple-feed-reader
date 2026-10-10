<?php

declare(strict_types=1);

namespace App\Service\LinkCard;

use App\Entity\Entry;
use App\Repository\EntryRepository;
use App\Repository\LinkCardRepository;
use App\Repository\TriedPostRepository;
use App\Service\LinkCard\Exception\FetchBudgetSpentException;
use App\Service\LinkCard\Pass\FetchBudget;
use App\Service\LinkCard\Support\LinkPost;
use App\Service\LinkCard\Support\SharedLinks;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the link cards of the posts a refresh creates, and of any post still without them when it is opened or a
 * client asks to complete it.
 */
final readonly class PostLinkCards
{
    private const int FETCHES_PER_REFRESH = 10;
    private const int FETCHES_PER_COMPLETION = 5;

    public function __construct(
        private PostCardWriter $writer,
        private TriedPostRepository $triedPosts,
        private EntryRepository $entries,
        private LinkCardRepository $linkCards,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The post the budget runs out on, and every post after it, is left unmarked for its first open.
     *
     * @param list<Entry> $createdEntries already flushed
     */
    public function forCreated(array $createdEntries): void
    {
        $this->forPosts(array_filter($createdEntries, LinkPost::matches(...)), self::FETCHES_PER_REFRESH);
    }

    /**
     * The post the budget runs out on, and every post after it, stays untried for the client's next call.
     *
     * @param list<Entry> $pendingEntries
     */
    public function forPending(array $pendingEntries): void
    {
        $this->forPosts(array_filter($pendingEntries, $this->isUntriedPost(...)), self::FETCHES_PER_COMPLETION);
    }

    public function forOpened(int $entryId): void
    {
        if ($this->triedPosts->wasTried($entryId)) {
            return;
        }
        $entry = $this->entries->find($entryId);
        if ($entry === null || !LinkPost::matches($entry) || $this->linkCards->hasAnyFor($entry)) {
            return;
        }
        $this->writeAndMark($entry, new FetchBudget(SharedLinks::MAX));
    }

    /** A failure is logged, unless it closed the EntityManager: rethrown, so the caller stops. */
    private function writeAndMark(Entry $post, FetchBudget $budget): void
    {
        try {
            $this->writer->write($post, $budget);
        } catch (\Exception $exception) {
            if ($exception instanceof FetchBudgetSpentException || !$this->entityManager->isOpen()) {
                throw $exception;
            }
            $this->logger->error(
                'Link cards failed for entry {entry}',
                ['entry' => $post->requireId(), 'exception' => $exception],
            );

            return;
        }
        $this->triedPosts->mark($post->requireId());
    }

    /** @param array<Entry> $posts */
    private function forPosts(array $posts, int $fetches): void
    {
        $budget = new FetchBudget($fetches);
        try {
            foreach ($posts as $post) {
                $this->writeAndMark($post, $budget);
            }
        } catch (FetchBudgetSpentException) {
            return;
        }
    }

    private function isUntriedPost(Entry $entry): bool
    {
        return LinkPost::matches($entry) && !$this->triedPosts->wasTried($entry->requireId());
    }
}
