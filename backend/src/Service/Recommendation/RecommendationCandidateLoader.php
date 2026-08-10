<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Repository\SubscriptionDisplayTitle;
use App\Repository\UnreadDql;
use App\Service\PlainText;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Loads the pool of unread candidates the recommendation prompt picks from,
 * and re-resolves a checkpointed batch of entry ids back to prompt lines.
 *
 * Both stay scoped to feeds the reader subscribes to — the same subscription
 * gate EntryRepository::rowQueryBuilder applies, including the per-user
 * customTitle override — because both render lines for the same prompt: a
 * retried batch must name a feed the same way the first attempt did.
 * linesForIds() drops only the unread predicate, so a resumed run can retry
 * its exact snapshot batch even for an entry the reader has since read.
 * It keeps the Subscription join, so an entry whose feed the reader has
 * since unsubscribed from still drops out with it, same as everywhere else
 * in the app; only outright deletion of the entry itself is special-cased
 * (silently dropped from the result rather than failing the batch).
 */
final readonly class RecommendationCandidateLoader
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Unread candidates in feeds the reader subscribes to. The newest
     * $poolSize are selected, then returned in a randomized order seeded by
     * $orderSeed, so batches sample the pool rather than cluster by recency
     * (#344). The same seed always produces the same order.
     *
     * @return list<PromptLine>
     */
    public function load(int $userId, int $poolSize, int $orderSeed): array
    {
        $qb = $this->candidateQueryBuilder($userId)
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->andWhere(UnreadDql::predicate())
            ->orderBy('e.effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($poolSize)
            ->setParameter('readFalse', false, Types::BOOLEAN);

        $lines = $this->linesFor($qb);
        $shuffled = (new Randomizer(new Mt19937($orderSeed)))->shuffleArray($lines);

        // shuffleArray() has no generic stub, so it widens the element type
        // back to mixed; the instanceof re-narrows it to the PromptLine list
        // the return type promises, dropping nothing — every element is one.
        return array_values(array_filter(
            $shuffled,
            static fn (mixed $line): bool => $line instanceof PromptLine,
        ));
    }

    /**
     * Re-resolves a checkpointed batch of entry ids, dropping any id whose
     * entry was pruned (deleted) since the snapshot was taken.
     *
     * @param list<int> $entryIds
     *
     * @return array<int, PromptLine>
     */
    public function linesForIds(int $userId, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $qb = $this->candidateQueryBuilder($userId)
            ->andWhere('e.id IN (:ids)')
            ->setParameter('ids', $entryIds);

        $linesById = [];
        foreach ($this->linesFor($qb) as $line) {
            /** @var int $entryId non-null: every line here came from an Entry row */
            $entryId = $line->entryId;
            $linesById[$entryId] = $line;
        }

        return $linesById;
    }

    /**
     * Counts the present entries among $entryIds and the date span they cover,
     * in one aggregate query scoped through the SAME subscription gate the
     * candidate lines use — so a pruned or unsubscribed id drops out of the
     * total and the range alike, consistent with the lines the model sees.
     * Returns null when the id set resolves to nothing.
     *
     * @param list<int> $entryIds
     */
    public function summarize(int $userId, array $entryIds): ?CandidatePoolSummary
    {
        if ($entryIds === []) {
            return null;
        }

        /** @var array{total: int, oldest: ?string, newest: ?string} $row */
        $row = $this->candidateQueryBuilder($userId)
            ->select('COUNT(e.id) AS total', 'MIN(e.effectiveDate) AS oldest', 'MAX(e.effectiveDate) AS newest')
            ->andWhere('e.id IN (:ids)')
            ->setParameter('ids', $entryIds)
            ->getQuery()
            ->getSingleResult();

        return $this->hydrateSummary($row);
    }

    /**
     * @param array{total: int, oldest: ?string, newest: ?string} $row an aggregate row over the scoped id set
     */
    private function hydrateSummary(array $row): ?CandidatePoolSummary
    {
        $oldest = $row['oldest'];
        $newest = $row['newest'];
        if (!\is_string($oldest) || !\is_string($newest)) {
            return null;
        }

        return new CandidatePoolSummary(
            total: (int) $row['total'],
            oldest: (new \DateTimeImmutable($oldest))->format('Y-m-d'),
            newest: (new \DateTimeImmutable($newest))->format('Y-m-d'),
        );
    }

    private function candidateQueryBuilder(int $userId): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('e', 'f', 's.customTitle AS customTitle')
            ->from(Entry::class, 'e')
            ->join('e.feed', 'f')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->setParameter('user', $userId);
    }

    /**
     * @return list<PromptLine>
     */
    private function linesFor(QueryBuilder $qb): array
    {
        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(fn (array $row): PromptLine => $this->hydrateLine($row), $rows);
    }

    /**
     * The joined 'f' select exists to eagerly fetch the feed in the same
     * query rather than lazy-loading it per row; because it is reachable via
     * a to-one association from the root 'e', Doctrine folds it into the
     * graph instead of giving it its own row index — only the Entry root and
     * the optional scalar customTitle appear as row keys.
     *
     * @param array<array-key, mixed> $row a mixed DQL result: [0 => Entry, customTitle: ?string]
     */
    private function hydrateLine(array $row): PromptLine
    {
        /** @var Entry $entry */
        $entry = $row[0];
        $feed = $entry->getFeed();
        $customTitle = $row['customTitle'];
        $feedName = SubscriptionDisplayTitle::from(
            \is_string($customTitle) ? $customTitle : null,
            $feed->getTitle(),
            $feed->getUrl(),
        );

        return new PromptLine(
            entryId: $entry->getId(),
            title: $entry->getTitle(),
            feedName: $feedName,
            date: $entry->getEffectiveDate()->format('Y-m-d'),
            description: PlainText::from($entry->getSummary() ?? $entry->getContentHtml()),
        );
    }
}
