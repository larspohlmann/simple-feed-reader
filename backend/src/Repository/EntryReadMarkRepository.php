<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Enum\CopyReadChange;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bulk read-flips of the caller's entry states, and the set-based writes that carry a read state to every other copy
 * of the same item or article. Creating a missing row for a flipped entry is the caller's job; a copy's is not.
 */
final readonly class EntryReadMarkRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @param list<int> $feedIds */
    public function hideUnreadInFeedsUntil(ReadMarking $marking, array $feedIds, \DateTimeImmutable $until): void
    {
        $this->entityManager->createQuery(sprintf(
            'UPDATE %s es SET es.isHidden = :true, es.hiddenAt = :now
                 WHERE es.user = :user AND es.isHidden = :false
                 AND es.entry IN (
                     SELECT e.id FROM %s e
                     WHERE e.feed IN (:feeds) AND e.dates.effectiveDate <= :until
                 )',
            EntryState::class,
            Entry::class,
        ))
            ->setParameter('true', true, Types::BOOLEAN)
            ->setParameter('false', false, Types::BOOLEAN)
            ->setParameter('now', $marking->at, Types::DATETIME_IMMUTABLE)
            ->setParameter('user', $marking->userId)
            ->setParameter('feeds', $feedIds)
            ->setParameter('until', $until, Types::DATETIME_IMMUTABLE)
            ->execute();
    }

    /** @param list<int> $entryIds */
    public function hideUnreadAmong(ReadMarking $marking, array $entryIds): void
    {
        $this->entityManager->createQuery(
            'UPDATE ' . EntryState::class . ' es
             SET es.isHidden = :true, es.hiddenAt = :now
             WHERE es.user = :user AND es.isHidden = :false AND IDENTITY(es.entry) IN (:ids)',
        )
            ->setParameter('true', true, Types::BOOLEAN)
            ->setParameter('false', false, Types::BOOLEAN)
            ->setParameter('now', $marking->at, Types::DATETIME_IMMUTABLE)
            ->setParameter('user', $marking->userId)
            ->setParameter('ids', $entryIds)
            ->execute();
    }

    /**
     * Writes $change onto the caller's subscribed copies of $entryIds that share their canonical item, the entries
     * themselves left out.
     *
     * @param list<int> $entryIds
     */
    public function spreadToItemCopies(CopyReadChange $change, ReadMarking $marking, array $entryIds): void
    {
        $this->spreadToCopies($change, $marking, CopySelection::fromItemSources($entryIds));
    }

    /**
     * Writes $change onto the caller's subscribed copies of $entryIds at the same address, where neither copy
     * belongs to a canonical item; the entries themselves left out.
     *
     * @param list<int> $entryIds
     */
    public function spreadToAddressCopies(CopyReadChange $change, ReadMarking $marking, array $entryIds): void
    {
        $this->spreadToCopies($change, $marking, CopySelection::fromAddressSources($entryIds));
    }

    /**
     * Marks read the caller's subscribed copies, in those feeds or not, of every item an entry of $feedIds dated no
     * later than $until belongs to.
     *
     * @param list<int> $feedIds
     */
    public function spreadReadFromFeedsUntil(ReadMarking $marking, array $feedIds, \DateTimeImmutable $until): void
    {
        $this->spreadToCopies(CopyReadChange::Read, $marking, CopySelection::fromSweptFeeds($feedIds, $until));
    }

    /**
     * One set-based insert: each subscriber of an entry's feed who read another copy of its canonical item, by a
     * hidden state or a watermark, gets a hidden (not viewed) state on the entry.
     *
     * @param list<int> $entryIds
     *
     * @return int the states inserted
     */
    public function inheritReadFromItemCopies(array $entryIds, \DateTimeImmutable $hiddenAt): int
    {
        if ($entryIds === []) {
            return 0;
        }

        $connection = $this->entityManager->getConnection();

        return (int) $connection->executeStatement(
            sprintf(
                'INSERT %s INTO entry_state'
                . ' (user_id, entry_id, is_hidden, hidden_at, is_favorite, is_kept, is_viewed, viewed_at)'
                . ' SELECT DISTINCT reader.user_id, arrived.id, :read, :hiddenAt, :unset, :unset, :unset, NULL'
                . ' FROM entry arrived'
                . ' JOIN subscription reader ON reader.feed_id = arrived.feed_id'
                . ' WHERE arrived.id IN (:ids) AND EXISTS ('
                . ' SELECT 1 FROM entry read_copy'
                . ' LEFT JOIN entry_state read_state'
                . ' ON read_state.entry_id = read_copy.id AND read_state.user_id = reader.user_id'
                . ' LEFT JOIN subscription read_through'
                . ' ON read_through.feed_id = read_copy.feed_id AND read_through.user_id = reader.user_id'
                . ' WHERE read_copy.canonical_item_id = arrived.canonical_item_id AND read_copy.id <> arrived.id'
                . ' AND (read_state.is_hidden = :read OR (read_state.is_hidden IS NULL'
                . ' AND read_copy.effective_date <= read_through.marked_read_until)))',
                DatabasePlatform::insertIgnoreClause($connection),
            ),
            ['read' => true, 'hiddenAt' => $hiddenAt, 'unset' => false, 'ids' => $entryIds],
            [
                'read' => Types::BOOLEAN,
                'hiddenAt' => Types::DATETIME_IMMUTABLE,
                'unset' => Types::BOOLEAN,
                'ids' => ArrayParameterType::INTEGER,
            ],
        );
    }

    /**
     * One upsert: each copy the caller follows that $change would alter gets the four read flags it leaves, a
     * missing row included; favourite and kept stay per copy.
     */
    private function spreadToCopies(CopyReadChange $change, ReadMarking $marking, CopySelection $copies): void
    {
        if ($copies->isEmpty()) {
            return;
        }

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            sprintf(
                'INSERT INTO entry_state'
                . ' (user_id, entry_id, is_hidden, hidden_at, is_viewed, viewed_at, is_favorite, is_kept)'
                . ' SELECT DISTINCT reader.user_id, copy.id, %s, :no, :no'
                . ' FROM (%s) copy'
                . ' JOIN subscription reader ON reader.feed_id = copy.feed_id AND reader.user_id = :user'
                . ' LEFT JOIN entry_state copy_state'
                . ' ON copy_state.entry_id = copy.id AND copy_state.user_id = reader.user_id'
                . ' WHERE %s %s',
                self::buildFlagColumns($change),
                $copies->sql,
                self::buildAlteredPredicate($change),
                DatabasePlatform::buildUpsertClause(
                    $connection,
                    ['user_id', 'entry_id'],
                    ['is_hidden', 'hidden_at', 'is_viewed', 'viewed_at'],
                ),
            ),
            ['user' => $marking->userId, 'at' => $marking->at, 'yes' => true, 'no' => false, ...$copies->parameters],
            [
                'user' => Types::INTEGER,
                'at' => Types::DATETIME_IMMUTABLE,
                'yes' => Types::BOOLEAN,
                'no' => Types::BOOLEAN,
                ...$copies->types,
            ],
        );
    }

    /** is_hidden, hidden_at, is_viewed and viewed_at as the entity's own transition leaves them. */
    private static function buildFlagColumns(CopyReadChange $change): string
    {
        return match ($change) {
            CopyReadChange::Read => ':yes, :at, :no, NULL',
            CopyReadChange::Unread => ':no, NULL, :no, NULL',
            CopyReadChange::Viewed => ':yes, CASE WHEN copy_state.is_hidden = :yes THEN copy_state.hidden_at'
                . ' WHEN copy_state.is_hidden IS NULL AND copy.effective_date <= reader.marked_read_until'
                . ' THEN reader.marked_read_until ELSE :at END, :yes, :at',
            CopyReadChange::Unviewed => 'copy_state.is_hidden, copy_state.hidden_at, :no, NULL',
        };
    }

    private static function buildAlteredPredicate(CopyReadChange $change): string
    {
        return match ($change) {
            CopyReadChange::Read => '(copy_state.is_hidden = :no OR (copy_state.is_hidden IS NULL'
                . ' AND (reader.marked_read_until IS NULL OR copy.effective_date > reader.marked_read_until)))',
            CopyReadChange::Unread => '(copy_state.is_hidden = :yes OR (copy_state.is_hidden IS NULL'
                . ' AND copy.effective_date <= reader.marked_read_until))',
            CopyReadChange::Viewed => '(copy_state.is_viewed IS NULL OR copy_state.is_viewed = :no)',
            CopyReadChange::Unviewed => 'copy_state.is_viewed = :yes',
        };
    }
}
