<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Membership;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\SearchTermsPredicateBuilder;
use App\Service\Search\Membership\DatabaseSavedSearchMatcher;
use App\Service\Search\SavedSearchTerm;
use App\Service\Search\SearchMode;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;

/** ASCII terms only: SQLite's LIKE folds ASCII case alone. */
final class DatabaseSavedSearchMatcherTest extends DbTestCase
{
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($this->feed);
        $this->em->flush();
    }

    public function testAnswersEveryRequestedSearchWithItsMatchesAmongTheCandidates(): void
    {
        $climate = $this->entry('a', 'Climate report');
        $rocket = $this->entry('b', 'Rocket launch', summary: 'Climate of Mars');
        $this->entry('c', 'Nothing here');

        $matches = $this->matcher()->matchingIds(
            [$this->search(1, 'climate'), $this->search(2, 'rocket'), $this->search(3, 'zebra')],
            [$climate->requireId(), $rocket->requireId()],
        );

        self::assertSame([
            1 => [$climate->getId(), $rocket->getId()],
            2 => [$rocket->getId()],
            3 => [],
        ], $matches);
    }

    public function testAMatchOutsideTheCandidatesIsNotReturned(): void
    {
        $inside = $this->entry('a', 'Climate report');
        $this->entry('b', 'Climate too');

        $matches = $this->matcher()->matchingIds([$this->search(1, 'climate')], [$inside->requireId()]);

        self::assertSame([1 => [$inside->getId()]], $matches);
    }

    public function testWholeWordModeRejectsASubstringHit(): void
    {
        $word = $this->entry('a', 'A punk show');
        $this->entry('b', 'Punktlich arrival');

        $matches = $this->matcher()->matchingIds(
            [$this->search(1, 'punk', SearchMode::WholeWord)],
            [$word->requireId(), $this->entry('c', 'Spunky')->requireId()],
        );

        self::assertSame([1 => [$word->getId()]], $matches);
    }

    public function testNoCandidatesAnswersEverySearchWithNothing(): void
    {
        self::assertSame([1 => [], 2 => []], $this->matcher()->matchingIds(
            [$this->search(1, 'climate'), $this->search(2, 'rocket')],
            [],
        ));
    }

    public function testMoreSearchesThanOneStatementHoldsAreStillAllAnswered(): void
    {
        $entry = $this->entry('a', 'term07 and term30');
        $searches = [];
        for ($i = 1; $i <= 30; $i++) {
            $searches[] = $this->search($i, \sprintf('term%02d', $i));
        }

        $matches = $this->matcher()->matchingIds($searches, [$entry->requireId()]);

        self::assertCount(30, $matches);
        self::assertSame([$entry->getId()], $matches[7]);
        self::assertSame([$entry->getId()], $matches[30]);
        self::assertSame([], $matches[8]);
    }

    private function matcher(): DatabaseSavedSearchMatcher
    {
        return new DatabaseSavedSearchMatcher($this->em, new SearchTermsPredicateBuilder());
    }

    private function search(int $id, string $term, SearchMode $mode = SearchMode::Substring): SavedSearchTerm
    {
        return new SavedSearchTerm($id, SearchTerms::fromTermAndMode($term, $mode));
    }

    private function entry(string $guid, string $title, ?string $summary = null): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            $title,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $entry->setSummary($summary);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }
}
