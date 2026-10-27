<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Membership;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\TranscriptParagraph;
use App\Entity\User;
use App\Enum\TranscriptFormat;
use App\Repository\SavedSearchEntryMembershipRepository;
use App\Repository\SavedSearchRepository;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Membership\SavedSearchRematch;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RecordingSavedSearchMatcher;
use Symfony\Component\Clock\MockClock;

final class SavedSearchRematchTest extends DbTestCase
{
    public function testAnEntryGainsTheSearchWhoseTermIsOnlyInItsTranscript(): void
    {
        $search = $this->search('quantum');
        $other = $this->search('rocket');
        $entry = $this->entryWithTranscript('quantum computing explained');

        $this->rematch()->rematch([$entry]);

        self::assertSame([$entry->requireId()], $this->memberEntryIds($search));
        self::assertSame([], $this->memberEntryIds($other));
    }

    public function testASecondRematchKeepsOneRow(): void
    {
        $search = $this->search('quantum');
        $entry = $this->entryWithTranscript('quantum computing explained');

        $this->rematch()->rematch([$entry]);
        $this->rematch()->rematch([$entry]);

        self::assertSame([$entry->requireId()], $this->memberEntryIds($search));
    }

    public function testNoEntriesOrNoSearchesTouchNothing(): void
    {
        $entry = $this->entryWithTranscript('quantum');
        $this->rematch()->rematch([$entry]);
        $this->rematch()->rematch([]);

        self::assertSame(
            [],
            $this->entityManager->getConnection()->fetchFirstColumn('SELECT entry_id FROM saved_search_entry'),
        );
    }

    public function testTheMatcherIsAskedAboutEveryEntryAndEverySearchInOneCall(): void
    {
        $first = $this->search('quantum');
        $second = $this->search('rocket');
        $entry = $this->entryWithTranscript('quantum');
        $matcher = new RecordingSavedSearchMatcher([$second->requireId() => [$entry->requireId()]]);

        $this->rematchWith($matcher)->rematch([$entry]);

        self::assertSame(
            [['searchIds' => [$first->requireId(), $second->requireId()], 'candidates' => [$entry->requireId()]]],
            $matcher->calls,
        );
        self::assertSame([], $this->memberEntryIds($first));
        self::assertSame([$entry->requireId()], $this->memberEntryIds($second));
    }

    public function testNothingIsAskedWithoutEntries(): void
    {
        $this->search('quantum');
        $matcher = new RecordingSavedSearchMatcher();

        $this->rematchWith($matcher)->rematch([]);

        self::assertSame([], $matcher->calls);
    }

    public function testNothingIsAskedWithoutSavedSearches(): void
    {
        $entry = $this->entryWithTranscript('quantum');
        $matcher = new RecordingSavedSearchMatcher();

        $this->rematchWith($matcher)->rematch([$entry]);

        self::assertSame([], $matcher->calls);
    }

    public function testAnUnavailableEngineIsLoggedAndStoresNothing(): void
    {
        $search = $this->search('quantum');
        $entry = $this->entryWithTranscript('quantum');
        $logger = new RecordingLogger();
        $matcher = new RecordingSavedSearchMatcher([], new SearchEngineUnavailableException('down'));

        $this->rematchWith($matcher, $logger)->rematch([$entry]);

        self::assertSame([], $this->memberEntryIds($search));
        self::assertCount(1, $logger->records);
    }

    private function rematchWith(
        RecordingSavedSearchMatcher $matcher,
        ?RecordingLogger $logger = null,
    ): SavedSearchRematch {
        $searches = self::getContainer()->get(SavedSearchRepository::class);
        $memberships = self::getContainer()->get(SavedSearchEntryMembershipRepository::class);
        self::assertInstanceOf(SavedSearchRepository::class, $searches);
        self::assertInstanceOf(SavedSearchEntryMembershipRepository::class, $memberships);

        return new SavedSearchRematch(
            $searches,
            $matcher,
            $memberships,
            new MockClock('2026-10-27 12:00:00'),
            $logger ?? new RecordingLogger(),
        );
    }

    private function rematch(): SavedSearchRematch
    {
        $rematch = self::getContainer()->get(SavedSearchRematch::class);
        self::assertInstanceOf(SavedSearchRematch::class, $rematch);

        return $rematch;
    }

    private function search(string $term): SavedSearch
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'rematch@example.com']);
        if ($user === null) {
            $user = new User('rematch@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
            $this->entityManager->persist($user);
        }
        $search = new SavedSearch($user, $term, false);
        $this->entityManager->persist($search);
        $this->entityManager->flush();

        return $search;
    }

    private function entryWithTranscript(string $text): Entry
    {
        $feed = new Feed('https://pod.test/feed.xml');
        $this->entityManager->persist($feed);
        $entry = new Entry(
            $feed,
            'guid-1',
            'https://pod.test/ep1',
            'Episode 1',
            new \DateTimeImmutable('2026-10-27 06:00:00'),
            new \DateTimeImmutable('2026-10-27 05:00:00'),
        );
        $this->entityManager->persist($entry);
        $transcript = new EntryTranscript($entry, 'https://pod.test/ep1.vtt', TranscriptFormat::Vtt, null);
        $transcript->fill([new TranscriptParagraph(0, null, $text)], $text);
        $this->entityManager->persist($transcript);
        $this->entityManager->flush();

        return $entry;
    }

    /** @return list<int> */
    private function memberEntryIds(SavedSearch $search): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT entry_id FROM saved_search_entry WHERE saved_search_id = ? ORDER BY entry_id',
            [$search->getId()],
        );

        return array_map(intval(...), $ids);
    }
}
