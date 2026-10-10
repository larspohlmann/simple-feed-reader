<?php

declare(strict_types=1);

namespace App\Service\LinkCard;

use App\Entity\Entry;
use App\Repository\EntryRepository;
use App\Repository\LinkCardRepository;
use App\Service\Ingest\Support\AtPostUri;
use App\Service\LinkCard\Exception\FetchBudgetSpentException;
use App\Service\LinkCard\Pass\FetchBudget;
use App\Service\LinkCard\Support\SharedLinks;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/** Builds the link cards of the posts a refresh creates, and of any post still without them when it is opened. */
final readonly class PostLinkCards
{
    private const int FETCHES_PER_REFRESH = 10;

    public function __construct(
        private PostCardWriter $writer,
        private TriedPosts $triedPosts,
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
        $budget = new FetchBudget(self::FETCHES_PER_REFRESH);
        try {
            foreach (array_filter($createdEntries, self::isPost(...)) as $post) {
                $this->writeAndMark($post, $budget);
            }
        } catch (FetchBudgetSpentException) {
            return;
        }
    }

    public function forOpened(int $entryId): void
    {
        if ($this->triedPosts->wasTried($entryId)) {
            return;
        }
        $entry = $this->entries->find($entryId);
        if ($entry === null || !self::isPost($entry) || $this->linkCards->hasAnyFor($entry)) {
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

    private static function isPost(Entry $entry): bool
    {
        return $entry->isTitleDerived() && !AtPostUri::matches($entry->getGuid());
    }
}
