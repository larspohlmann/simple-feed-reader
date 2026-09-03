<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SavedSearchEntryRepository;
use App\Service\Search\SavedSearchTerm;
use App\Service\Search\SearchMode;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;

/**
 * The unread ids behind every saved-search badge, read in one scan (#584).
 * ASCII terms only — the suite runs on SQLite, whose LIKE folds ASCII case
 * alone.
 */
final class SavedSearchUnreadMatchIdsTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('badges@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);
        $this->em->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->em->flush();
    }

    public function testGroupsUnreadMatchesBySavedSearch(): void
    {
        $policy = $this->entry('a', 'Climate policy update');
        $recap = $this->entry('b', 'Climate summit recap');
        $launch = $this->entry('c', 'Rocket launch');
        $this->hide($this->entry('d', 'Climate deal signed'));
        $this->entry('e', 'Unrelated cooking post');

        $idsBySearch = $this->idsBySearch(['climate', 'rocket']);

        self::assertSame(
            [10 => [$policy->getId(), $recap->getId()], 20 => [$launch->getId()]],
            $idsBySearch,
        );
    }

    public function testAnEntryMatchingTwoSavedSearchesLandsInBothSets(): void
    {
        $both = $this->entry('a', 'Climate rocket');

        self::assertSame(
            [10 => [$both->getId()], 20 => [$both->getId()]],
            $this->idsBySearch(['climate', 'rocket']),
        );
    }

    public function testASavedSearchWithoutMatchesAnswersAnEmptySet(): void
    {
        $climate = $this->entry('a', 'Climate report');

        self::assertSame([10 => [$climate->getId()], 20 => []], $this->idsBySearch(['climate', 'rocket']));
    }

    public function testWholeWordMatchIsStricterThanSubstring(): void
    {
        $revival = $this->entry('a', 'A punk revival');
        $gadgets = $this->entry('b', 'Steampunk gadgets');

        $idsBySearch = $this->repo()->unreadMatchIdsBySavedSearch(
            (int) $this->user->getId(),
            [
                new SavedSearchTerm(10, SearchTerms::fromTermAndMode('punk', SearchMode::WholeWord)),
                new SavedSearchTerm(20, SearchTerms::fromTermAndMode('punk', SearchMode::Substring)),
            ],
        );

        self::assertSame([10 => [$revival->getId()], 20 => [$revival->getId(), $gadgets->getId()]], $idsBySearch);
    }

    public function testNoSavedSearchesNeedsNoQuery(): void
    {
        $this->entry('a', 'Climate report');

        self::assertSame([], $this->idsBySearch([]));
    }

    public function testGroupingSurvivesAChunkBoundary(): void
    {
        $climate = $this->entry('a', 'Climate report');
        $rocket = $this->entry('b', 'Rocket launch');
        $terms = array_fill(0, SavedSearchEntryRepository::SEARCHES_PER_SCAN, 'nothing');
        $terms[0] = 'climate';
        $terms[] = 'rocket';

        $idsBySearch = $this->idsBySearch($terms);

        self::assertCount(SavedSearchEntryRepository::SEARCHES_PER_SCAN + 1, $idsBySearch);
        self::assertSame([$climate->getId()], $idsBySearch[10]);
        self::assertSame([$rocket->getId()], $idsBySearch[(SavedSearchEntryRepository::SEARCHES_PER_SCAN + 1) * 10]);
        self::assertSame([], $idsBySearch[20]);
    }

    /**
     * @param list<string> $terms
     *
     * @return array<int, list<int>>
     */
    private function idsBySearch(array $terms): array
    {
        return $this->repo()->unreadMatchIdsBySavedSearch(
            (int) $this->user->getId(),
            array_map(
                static fn (int $position, string $term): SavedSearchTerm => new SavedSearchTerm(
                    ($position + 1) * 10,
                    SearchTerms::fromTermAndMode($term, SearchMode::Substring),
                ),
                array_keys($terms),
                $terms,
            ),
        );
    }

    private function repo(): SavedSearchEntryRepository
    {
        $repo = self::getContainer()->get(SavedSearchEntryRepository::class);
        self::assertInstanceOf(SavedSearchEntryRepository::class, $repo);

        return $repo;
    }

    private function hide(Entry $entry): void
    {
        $state = new EntryState($this->user, $entry);
        $state->setIsHidden(true);
        $this->em->persist($state);
        $this->em->flush();
    }

    private function entry(string $guid, string $title): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            $title,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }
}
