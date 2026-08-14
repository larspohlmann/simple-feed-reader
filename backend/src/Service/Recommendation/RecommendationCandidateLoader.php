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
     * Unread candidates, excluding anything the reader has already favorited,
     * kept, or viewed, in feeds the reader subscribes to, and no older than
     * the request's window (#386). The newest $request->poolSize of those are
     * selected, then returned in a randomized order seeded by
     * $request->orderSeed, so batches sample the pool rather than cluster by
     * recency (#344). The same seed always produces the same order.
     *
     * @return list<PromptLine>
     */
    public function load(int $userId, CandidatePoolRequest $request): array
    {
        $qb = $this->candidateQueryBuilder($userId)
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->andWhere(UnreadDql::predicate())
            // This is the negation of what RecommendationHistoryLoader's
            // FAVORITES/KEPT/VIEWED sections contain -- a future change to
            // what counts as reader history there must update both.
            // Favorited, kept, and viewed entries are the reader's history —
            // they already appear in the prompt's FAVORITES/KEPT/VIEWED
            // sections, so scoring them again as fresh candidates would
            // re-recommend what the reader has already acted on. es is a
            // LEFT JOIN, so an entry with no state row (never interacted
            // with) must stay a candidate — hence the null-safe OR on each
            // flag.
            ->andWhere(
                '(es.isFavorite = :notInteracted OR es.isFavorite IS NULL)'
                . ' AND (es.isKept = :notInteracted OR es.isKept IS NULL)'
                . ' AND (es.isViewed = :notInteracted OR es.isViewed IS NULL)',
            )
            // The window is the reader's own look-back setting, already
            // resolved to an instant by the caller. Inclusive: an entry
            // stamped exactly at the boundary is inside the window.
            ->andWhere('e.effectiveDate >= :since')
            ->orderBy('e.effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($request->poolSize)
            ->setParameter('since', $request->since)
            ->setParameter('readFalse', false, Types::BOOLEAN)
            ->setParameter('notInteracted', false, Types::BOOLEAN);

        $lines = $this->linesFor($qb);

        // shuffleArray() has no generic stub, so it widens the element type
        // back to mixed; the @var restates the PromptLine list the return
        // type promises -- shuffling reorders $lines, it cannot change what
        // is in it.
        /** @var list<PromptLine> $shuffled */
        $shuffled = (new Randomizer(new Mt19937($request->orderSeed)))->shuffleArray($lines);

        return $shuffled;
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
