<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\EntryListJoinOrderWalker;
use App\Entity\Entry;
use App\Tests\DbTestCase;
use Doctrine\ORM\Query;

/**
 * The walker renders the join-order hint on the outer SELECT only, keyed to the
 * entry table's own SQL alias, and leaves a query without the hint untouched.
 */
final class EntryListJoinOrderWalkerTest extends DbTestCase
{
    private const string COLLAPSE_DQL =
        'SELECT e FROM %s e WHERE NOT EXISTS ('
        . 'SELECT 1 FROM %s e2 WHERE e2.urlHash = e.urlHash AND e2.id < e.id)';

    public function testHintPrefixesTheOuterSelectWithTheEntryAlias(): void
    {
        $sql = $this->collapseQuery()
            ->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, EntryListJoinOrderWalker::class)
            ->getSQL();
        self::assertIsString($sql);

        if (preg_match('/FROM entry (\w+)/', $sql, $match) !== 1) {
            self::fail('no entry table alias in ' . $sql);
        }
        self::assertStringStartsWith('SELECT /*+ JOIN_PREFIX(' . $match[1] . ') */ ', $sql);
    }

    public function testTheCollapseSubselectStaysUnhinted(): void
    {
        $sql = $this->collapseQuery()
            ->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, EntryListJoinOrderWalker::class)
            ->getSQL();
        self::assertIsString($sql);

        self::assertSame(1, substr_count($sql, 'JOIN_PREFIX'));
    }

    public function testWithoutTheHintNoOptimizerCommentIsEmitted(): void
    {
        $sql = $this->collapseQuery()->getSQL();
        self::assertIsString($sql);

        self::assertStringNotContainsString('JOIN_PREFIX', $sql);
    }

    public function testTheHintedQueryStillParsesAndRuns(): void
    {
        $rows = $this->collapseQuery()
            ->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, EntryListJoinOrderWalker::class)
            ->getResult();

        self::assertSame([], $rows);
    }

    /**
     * @return Query<mixed, mixed>
     */
    private function collapseQuery(): Query
    {
        return $this->em->createQuery(\sprintf(self::COLLAPSE_DQL, Entry::class, Entry::class));
    }
}
