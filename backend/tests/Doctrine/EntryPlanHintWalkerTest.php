<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\EntryPlanHint;
use App\Doctrine\EntryPlanHintWalker;
use App\Doctrine\Exception\MissingEntryPlanHintException;
use App\Entity\Entry;
use App\Tests\DbTestCase;
use Doctrine\ORM\Query;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The walker renders the chosen EntryPlanHint on the outer SELECT only, keyed
 * to the entry table's own SQL alias, leaves a query without the walker
 * untouched, and refuses to render without a valid plan hint.
 */
final class EntryPlanHintWalkerTest extends DbTestCase
{
    private const string COLLAPSE_DQL =
        'SELECT e FROM %s e WHERE NOT EXISTS ('
        . 'SELECT 1 FROM %s e2 WHERE e2.urlHash = e.urlHash AND e2.id < e.id)';

    /**
     * @return iterable<string, array{EntryPlanHint}>
     */
    public static function planHints(): iterable
    {
        yield 'date-ordered walk' => [EntryPlanHint::DateOrderedWalk];
        yield 'duplicate lookup' => [EntryPlanHint::DuplicateLookup];
    }

    /**
     * The expected optimizer comment is spelled out here, not derived from
     * optimizerHint(): a test that renders its own expectation from the code
     * under test cannot catch a mutation of that code. %1$s is the entry alias.
     *
     * @return iterable<string, array{EntryPlanHint, string}>
     */
    public static function planHintComments(): iterable
    {
        yield 'date-ordered walk' => [EntryPlanHint::DateOrderedWalk, 'JOIN_PREFIX(%1$s)'];
        yield 'duplicate lookup' => [
            EntryPlanHint::DuplicateLookup,
            'JOIN_PREFIX(%1$s) INDEX(%1$s idx_entry_url_hash)',
        ];
    }

    #[DataProvider('planHintComments')]
    public function testHintPrefixesTheOuterSelectWithTheEntryAlias(
        EntryPlanHint $planHint,
        string $expectedComment,
    ): void {
        $sql = $this->hintedCollapseQuery($planHint)->getSQL();
        self::assertIsString($sql);

        if (preg_match('/FROM entry (\w+)/', $sql, $match) !== 1) {
            self::fail('no entry table alias in ' . $sql);
        }
        self::assertStringStartsWith(
            'SELECT /*+ ' . \sprintf($expectedComment, $match[1]) . ' */ ',
            $sql,
        );
    }

    #[DataProvider('planHints')]
    public function testTheCollapseSubselectStaysUnhinted(EntryPlanHint $planHint): void
    {
        $sql = $this->hintedCollapseQuery($planHint)->getSQL();
        self::assertIsString($sql);

        self::assertSame(1, substr_count($sql, 'JOIN_PREFIX'));
    }

    #[DataProvider('planHints')]
    public function testTheHintedQueryStillParsesAndRuns(EntryPlanHint $planHint): void
    {
        $rows = $this->hintedCollapseQuery($planHint)->getResult();

        self::assertSame([], $rows);
    }

    public function testWithoutTheWalkerNoOptimizerCommentIsEmitted(): void
    {
        $sql = $this->collapseQuery()->getSQL();
        self::assertIsString($sql);

        self::assertStringNotContainsString('JOIN_PREFIX', $sql);
    }

    public function testAMissingPlanHintFailsLoudly(): void
    {
        $this->expectException(MissingEntryPlanHintException::class);

        $this->collapseQuery()
            ->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, EntryPlanHintWalker::class)
            ->getSQL();
    }

    public function testTheMissingHintMessageNamesTheWalkerAndTheHint(): void
    {
        self::assertSame(
            EntryPlanHintWalker::class . ' requires a valid ' . EntryPlanHint::class . ' hint.',
            (new MissingEntryPlanHintException())->getMessage(),
        );
    }

    public function testTheUrlHashIndexNameMatchesEntrysOrmMetadata(): void
    {
        $indexes = $this->em->getClassMetadata(Entry::class)->table['indexes'] ?? [];

        self::assertArrayHasKey(EntryPlanHint::URL_HASH_INDEX_NAME, $indexes);
    }

    /**
     * @return Query<mixed, mixed>
     */
    private function hintedCollapseQuery(EntryPlanHint $planHint): Query
    {
        return $this->collapseQuery()
            ->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, EntryPlanHintWalker::class)
            ->setHint(EntryPlanHintWalker::HINT, $planHint);
    }

    /**
     * @return Query<mixed, mixed>
     */
    private function collapseQuery(): Query
    {
        return $this->em->createQuery(\sprintf(self::COLLAPSE_DQL, Entry::class, Entry::class));
    }
}
