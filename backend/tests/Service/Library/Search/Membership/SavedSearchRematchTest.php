<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Membership;

use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\SavedSearchEntryMembershipRepository;
use App\Repository\SavedSearchRepository;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Membership\SavedSearchRematch;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RecordingSavedSearchMatcher;
use App\Tests\Support\TranscriptFixtures;
use App\Tests\Support\TranscriptSearchFixtures;
use Symfony\Component\Clock\MockClock;

final class SavedSearchRematchTest extends DbTestCase
{
    private TranscriptFixtures $fixtures;
    private TranscriptSearchFixtures $searchFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new TranscriptFixtures($this->entityManager);
        $this->searchFixtures = new TranscriptSearchFixtures($this->entityManager);
    }

    public function testAnEntryGainsTheSearchWhoseTermIsOnlyInItsTranscript(): void
    {
        $search = $this->search('quantum');
        $other = $this->search('rocket');
        $entry = $this->fixtures->entryWithFilledTranscript('quantum computing explained');

        $this->rematch()->rematch([$entry]);

        self::assertSame([$entry->requireId()], $this->searchFixtures->memberEntryIds($search));
        self::assertSame([], $this->searchFixtures->memberEntryIds($other));
    }

    public function testASecondRematchKeepsOneRow(): void
    {
        $search = $this->search('quantum');
        $entry = $this->fixtures->entryWithFilledTranscript('quantum computing explained');

        $this->rematch()->rematch([$entry]);
        $this->rematch()->rematch([$entry]);

        self::assertSame([$entry->requireId()], $this->searchFixtures->memberEntryIds($search));
    }

    public function testNoEntriesOrNoSearchesTouchNothing(): void
    {
        $entry = $this->fixtures->entryWithFilledTranscript('quantum');
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
        $entry = $this->fixtures->entryWithFilledTranscript('quantum');
        $matcher = new RecordingSavedSearchMatcher([$second->requireId() => [$entry->requireId()]]);

        $this->rematchWith($matcher)->rematch([$entry]);

        self::assertSame(
            [['searchIds' => [$first->requireId(), $second->requireId()], 'candidates' => [$entry->requireId()]]],
            $matcher->calls,
        );
        self::assertSame([], $this->searchFixtures->memberEntryIds($first));
        self::assertSame([$entry->requireId()], $this->searchFixtures->memberEntryIds($second));
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
        $entry = $this->fixtures->entryWithFilledTranscript('quantum');
        $matcher = new RecordingSavedSearchMatcher();

        $this->rematchWith($matcher)->rematch([$entry]);

        self::assertSame([], $matcher->calls);
    }

    public function testAnUnavailableEngineIsLoggedAndStoresNothing(): void
    {
        $search = $this->search('quantum');
        $entry = $this->fixtures->entryWithFilledTranscript('quantum');
        $logger = new RecordingLogger();
        $matcher = new RecordingSavedSearchMatcher([], new SearchEngineUnavailableException('down'));

        $this->rematchWith($matcher, $logger)->rematch([$entry]);

        self::assertSame([], $this->searchFixtures->memberEntryIds($search));
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertInstanceOf(SearchEngineUnavailableException::class, $logger->records[0]['context']['exception']);
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
}
