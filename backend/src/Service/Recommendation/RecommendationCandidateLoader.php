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
     * Unread candidates in feeds the reader subscribes to, newest first,
     * capped to $poolSize.
     *
     * @return list<PromptLine>
     */
    public function load(int $userId, int $poolSize): array
    {
        $qb = $this->candidateQueryBuilder($userId)
            ->andWhere(UnreadDql::predicate())
            ->orderBy('e.effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($poolSize)
            ->setParameter('readFalse', false, Types::BOOLEAN);

        return $this->linesFor($qb);
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

    private function candidateQueryBuilder(int $userId): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('e', 'f', 's.customTitle AS customTitle')
            ->from(Entry::class, 'e')
            ->join('e.feed', 'f')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
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
