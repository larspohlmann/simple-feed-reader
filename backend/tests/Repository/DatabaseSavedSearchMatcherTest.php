<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Entity\Feed;
use App\Entity\TranscriptParagraph;
use App\Enum\TranscriptFormat;
use App\Repository\DatabaseSavedSearchMatcher;
use App\Repository\IndexSearchPredicates;
use App\Repository\LikeSearchPredicates;
use App\Repository\SearchTermsPredicateBuilder;
use App\Service\Search\Model\SavedSearchTermModel;
use App\Service\Search\Model\SearchMode;
use App\Service\Search\Model\SearchTermsModel;
use App\Tests\DbTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class DatabaseSavedSearchMatcherTest extends DbTestCase
{
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();
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

    public function testASavedSearchFlagsAnEntryWhoseTranscriptMatches(): void
    {
        $episode = $this->entry('a', 'Episode one');
        $transcript = new EntryTranscript($episode, 'https://example.com/t.vtt', TranscriptFormat::Vtt, null);
        $transcript->fill([new TranscriptParagraph(null, 'Ann', 'Ein Zwiebelring')], 'Ann: Ein Zwiebelring, bitte.');
        $this->entityManager->persist($transcript);
        $this->entityManager->flush();

        $matches = $this->matcher()->matchingIds([$this->search(1, 'Zwiebelring')], [$episode->requireId()]);

        self::assertSame([1 => [$episode->getId()]], $matches);
    }

    public function testASavedSearchIgnoresCaseForAnUmlautTerm(): void
    {
        $hit = $this->entry('a', 'ÄRGER im Büro');
        $miss = $this->entry('b', 'Nothing here');

        $matches = $this->matcher()->matchingIds([$this->search(1, 'ärger')], [$hit->requireId(), $miss->requireId()]);

        self::assertSame([1 => [$hit->getId()]], $matches);
    }

    public function testASavedSearchIgnoresAccents(): void
    {
        $hit = $this->entry('a', 'Ein Café in Zürich');
        $miss = $this->entry('b', 'Nothing here');

        $matches = $this->matcher()->matchingIds([$this->search(1, 'cafe')], [$hit->requireId(), $miss->requireId()]);

        self::assertSame([1 => [$hit->getId()]], $matches);
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

    public function testNoCandidatesRunsNoStatement(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('createQueryBuilder');
        $matcher = new DatabaseSavedSearchMatcher($entityManager, self::predicateBuilder());

        self::assertSame([1 => []], $matcher->matchingIds([$this->search(1, 'climate')], []));
    }

    public function testMoreSearchesThanOneStatementHoldsAreStillAllAnswered(): void
    {
        $entry = $this->entry('a', 'term07 and term30');
        $searches = [];
        for ($number = 1; $number <= 30; $number++) {
            $searches[] = $this->search($number, \sprintf('term%02d', $number));
        }

        $matches = $this->matcher()->matchingIds($searches, [$entry->requireId()]);

        self::assertCount(30, $matches);
        self::assertSame([$entry->getId()], $matches[7]);
        self::assertSame([$entry->getId()], $matches[30]);
        self::assertSame([], $matches[8]);
    }

    private function matcher(): DatabaseSavedSearchMatcher
    {
        return new DatabaseSavedSearchMatcher($this->entityManager, self::predicateBuilder());
    }

    private function search(int $id, string $term, SearchMode $mode = SearchMode::Substring): SavedSearchTermModel
    {
        return new SavedSearchTermModel($id, SearchTermsModel::fromTermAndMode($term, $mode));
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
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private static function predicateBuilder(): SearchTermsPredicateBuilder
    {
        return new SearchTermsPredicateBuilder(new LikeSearchPredicates(), new IndexSearchPredicates());
    }
}
