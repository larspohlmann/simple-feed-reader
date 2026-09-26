<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\Entry;
use App\Entity\EntryAttachment;
use App\Entity\EntryMedium;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryBatchInserter;
use App\Repository\EntryRepository;
use App\Repository\EntryStateRepository;
use App\Repository\FeedRepository;
use App\Service\Backup\BackupFitCheck;
use App\Service\Backup\BackupReader;
use App\Service\Backup\EntryPartInspector;
use App\Service\Backup\EntryPartRestorer;
use App\Service\Backup\Exception\BackupDoesNotFitException;
use App\Service\Backup\Exception\InvalidBackupException;
use App\Service\Backup\RestoreEntryLoaderFactory;
use App\Service\Search\EntryIndexer;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\UserFactory;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EntryPartRestorerTest extends DbTestCase
{
    private const string FEED_URL = 'https://entry-part.example/feed.xml';

    private UserFactory $users;
    private RecordingSearchIndexWriter $indexWriter;
    private static int $userSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->users = new UserFactory($this->em, $hasher);
        $this->indexWriter = new RecordingSearchIndexWriter();
    }

    public function testItCreatesTheEntriesAndTheirStates(): void
    {
        $user = $this->subscribedUser(self::FEED_URL);
        $gzip = $this->entryPart([
            $this->entryLine('a'),
            $this->entryLine('b'),
            $this->entryStateLine('a', isFavorite: true),
        ]);

        $result = $this->restorer()->load($user, $gzip);

        self::assertSame(2, $result->entries);
        self::assertSame(1, $result->entryStates);
        $this->em->clear();
        $entryA = $this->findEntry('a');
        $entryB = $this->findEntry('b');
        self::assertNotNull($entryA);
        self::assertNotNull($entryB);
        $state = $this->stateFor($user, $entryA);
        self::assertNotNull($state);
        self::assertTrue($state->isFavorite());
    }

    public function testReadMarksAreRestoredExactlyAsBackedUpIncludingALegacyUndatedOne(): void
    {
        $user = $this->subscribedUser(self::FEED_URL);
        $gzip = $this->entryPart([
            $this->entryLine('a'),
            $this->entryLine('b'),
            $this->entryLine('c'),
            array_replace($this->entryStateLine('a'), ['isHidden' => true, 'hiddenAt' => '2026-08-02T00:00:00+00:00']),
            array_replace($this->entryStateLine('b'), ['isHidden' => true, 'isKept' => true]),
            array_replace($this->entryStateLine('c'), ['hiddenAt' => '2026-08-03T00:00:00+00:00']),
        ]);

        $this->restorer()->load($user, $gzip);

        $this->em->clear();
        $dated = $this->restoredStateOf($user, 'a');
        self::assertTrue($dated->isHidden());
        self::assertEquals(new \DateTimeImmutable('2026-08-02 00:00:00'), $dated->getHiddenAt());
        self::assertFalse($dated->isKept());
        $legacy = $this->restoredStateOf($user, 'b');
        self::assertTrue($legacy->isHidden());
        self::assertNull($legacy->getHiddenAt());
        self::assertTrue($legacy->isKept());
        self::assertFalse($legacy->isFavorite());
        $staleUnread = $this->restoredStateOf($user, 'c');
        self::assertFalse($staleUnread->isHidden());
        self::assertEquals(new \DateTimeImmutable('2026-08-03 00:00:00'), $staleUnread->getHiddenAt());
    }

    public function testAStoredMediumOrAttachmentWithoutItsKeysIsLeftOutWhenRead(): void
    {
        $user = $this->subscribedUser(self::FEED_URL);
        $gzip = $this->entryPart([
            array_replace($this->entryLine('a'), [
                'media' => [
                    ['kind' => 'image'],
                    ['url' => 'https://i/no-kind.jpg'],
                    ['url' => 'https://i/kept.jpg', 'kind' => 'image'],
                ],
                'attachments' => [
                    ['mimeType' => 'audio/mpeg'],
                    ['url' => 'https://cdn/kept.mp3'],
                ],
            ]),
        ]);

        $this->restorer()->load($user, $gzip);

        $this->em->clear();
        $entry = $this->findEntry('a');
        self::assertNotNull($entry);
        self::assertSame(
            ['https://i/kept.jpg'],
            array_map(static fn (EntryMedium $medium): string => $medium->url, $entry->getMedia()),
        );
        self::assertSame(
            ['https://cdn/kept.mp3'],
            array_map(static fn (EntryAttachment $attachment): string => $attachment->url, $entry->getAttachments()),
        );
    }

    public function testARetriedPartCreatesNothingAndFailsNothing(): void
    {
        $user = $this->subscribedUser(self::FEED_URL);
        $userId = $user->requireId();
        $gzip = $this->entryPart([
            $this->entryLine('a'),
            $this->entryStateLine('a', isFavorite: true),
        ]);
        $restorer = $this->restorer();
        $restorer->load($user, $gzip);
        $this->em->clear();

        $second = $restorer->load($this->reloadUser($userId), $gzip);

        self::assertSame(0, $second->entries);
        self::assertSame(0, $second->entryStates);
        $this->em->clear();
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM entry'));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM entry_state'));
    }

    public function testAnEntryTheSchedulerAlreadyFetchedIsKeptAndStillGetsItsState(): void
    {
        $user = $this->subscribedUser(self::FEED_URL);
        $userId = $user->requireId();
        $feed = $this->feedByUrl(self::FEED_URL);
        $this->makeEntry($feed, 'a', 'Scheduler Title');
        $this->em->flush();

        $gzip = $this->entryPart([
            $this->entryLine('a', title: 'Backup Title'),
            $this->entryStateLine('a', isFavorite: true),
        ]);

        $result = $this->restorer()->load($this->reloadUser($userId), $gzip);

        self::assertSame(0, $result->entries);
        self::assertSame(1, $result->entryStates);
        $this->em->clear();
        $entry = $this->findEntry('a');
        self::assertNotNull($entry);
        self::assertSame('Scheduler Title', $entry->getTitle());
        $state = $this->stateFor($this->reloadUser($userId), $entry);
        self::assertNotNull($state);
        self::assertTrue($state->isFavorite());
    }

    public function testAnExistingStateRowIsLeftUntouched(): void
    {
        $user = $this->subscribedUser(self::FEED_URL);
        $userId = $user->requireId();
        $feed = $this->feedByUrl(self::FEED_URL);
        $entry = $this->makeEntry($feed, 'a', 'Title');
        $state = new EntryState($user, $entry);
        $state->clearFavorite();
        $this->em->persist($state);
        $this->em->flush();

        $gzip = $this->entryPart([$this->entryStateLine('a', isFavorite: true)]);

        $result = $this->restorer()->load($this->reloadUser($userId), $gzip);

        self::assertSame(0, $result->entryStates);
        $this->em->clear();
        $entry = $this->findEntry('a');
        self::assertNotNull($entry);
        $reloaded = $this->stateFor($this->reloadUser($userId), $entry);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isFavorite());
    }

    public function testAFeedAnotherAccountReadsGetsNoNewEntries(): void
    {
        $user = $this->subscribedUser(self::FEED_URL);
        $userId = $user->requireId();
        $feed = $this->feedByUrl(self::FEED_URL);
        $stranger = $this->users->create($this->nextEmail());
        $this->em->persist(new Subscription($stranger, $feed, new \DateTimeImmutable('2026-07-02 00:00:00')));
        $this->em->flush();

        $gzip = $this->entryPart([$this->entryLine('a')]);

        $result = $this->restorer()->load($this->reloadUser($userId), $gzip);

        self::assertSame(0, $result->entries);
        $this->em->clear();
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM entry'));
    }

    public function testAPartNamingAnUnsubscribedFeedIsRefusedBeforeAnyWrite(): void
    {
        $user = $this->subscribedUser(self::FEED_URL);
        $gzip = $this->entryPart([
            $this->entryLine('a'),
            $this->entryLine('b', feedUrl: 'https://foreign.example/feed.xml'),
        ]);

        try {
            $this->restorer()->load($user, $gzip);
            self::fail('An entry part naming an unsubscribed feed was accepted.');
        } catch (InvalidBackupException) {
            // Expected.
        }

        $this->em->clear();
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM entry'));
    }

    public function testAStateNamingAnUnsubscribedFeedIsRefused(): void
    {
        $user = $this->subscribedUser(self::FEED_URL);
        $gzip = $this->entryPart([
            $this->entryLine('a'),
            $this->entryStateLine('b', feedUrl: 'https://foreign.example/feed.xml'),
        ]);

        $this->expectException(InvalidBackupException::class);

        $this->restorer()->load($user, $gzip);
    }

    public function testTheFoundationIsRefused(): void
    {
        $user = $this->subscribedUser(self::FEED_URL);

        $this->expectException(InvalidBackupException::class);

        $this->restorer()->load($user, $this->foundationGzip());
    }

    public function testItRefusesAPartThatWouldBreachTheAccountEntryCeiling(): void
    {
        $user = $this->subscribedUser(self::FEED_URL);
        $userId = $user->requireId();
        $feed = $this->feedByUrl(self::FEED_URL);
        $this->makeEntry($feed, 'existing-a', 'A');
        $this->makeEntry($feed, 'existing-b', 'B');
        $this->em->flush();

        $gzip = $this->entryPart([$this->entryLine('new')]);

        $this->expectException(BackupDoesNotFitException::class);

        $this->restorer(accountEntryCeiling: 2)->load($this->reloadUser($userId), $gzip);
    }

    public function testAPartThatExactlyFillsTheAccountEntryCeilingIsAccepted(): void
    {
        $user = $this->subscribedUser(self::FEED_URL);
        $userId = $user->requireId();
        $feed = $this->feedByUrl(self::FEED_URL);
        $this->makeEntry($feed, 'existing-a', 'A');
        $this->em->flush();

        $gzip = $this->entryPart([$this->entryLine('new')]);

        $result = $this->restorer(accountEntryCeiling: 2)->load($this->reloadUser($userId), $gzip);

        self::assertSame(1, $result->entries);
    }

    public function testAPartReimportingAlreadyPresentEntriesFitsUnderTheCeiling(): void
    {
        $user = $this->subscribedUser(self::FEED_URL);
        $userId = $user->requireId();
        $feed = $this->feedByUrl(self::FEED_URL);
        $this->makeEntry($feed, 'a', 'A');
        $this->makeEntry($feed, 'b', 'B');
        $this->em->flush();

        $gzip = $this->entryPart([$this->entryLine('a'), $this->entryLine('b')]);

        $result = $this->restorer(accountEntryCeiling: 2)->load($this->reloadUser($userId), $gzip);

        self::assertSame(0, $result->entries);
    }

    public function testTheCeilingCountsOnlyTheGenuinelyNewEntriesOfAPart(): void
    {
        $user = $this->subscribedUser(self::FEED_URL);
        $userId = $user->requireId();
        $feed = $this->feedByUrl(self::FEED_URL);
        $this->makeEntry($feed, 'a', 'A');
        $this->em->flush();

        $gzip = $this->entryPart([
            $this->entryLine('a'),
            $this->entryLine('b'),
            $this->entryLine('c'),
        ]);

        $this->expectException(BackupDoesNotFitException::class);

        $this->restorer(accountEntryCeiling: 2)->load($this->reloadUser($userId), $gzip);
    }

    public function testTheCeilingSumsTheNewEntriesOfEveryFeedThePartNames(): void
    {
        $secondFeedUrl = 'https://second.example/feed.xml';
        $user = $this->subscribedUser(self::FEED_URL);
        $userId = $user->requireId();
        $this->subscribeTo($user, $secondFeedUrl);

        $gzip = $this->entryPart([
            $this->entryLine('a'),
            $this->entryLine('b', feedUrl: $secondFeedUrl),
        ]);

        $this->expectException(BackupDoesNotFitException::class);

        $this->restorer(accountEntryCeiling: 1)->load($this->reloadUser($userId), $gzip);
    }

    public function testItLoadsTheEntriesOfEveryFeedThePartNames(): void
    {
        $secondFeedUrl = 'https://second.example/feed.xml';
        $user = $this->subscribedUser(self::FEED_URL);
        $userId = $user->requireId();
        $this->subscribeTo($user, $secondFeedUrl);

        $gzip = $this->entryPart([
            $this->entryLine('a'),
            $this->entryLine('b', feedUrl: $secondFeedUrl),
        ]);

        $result = $this->restorer()->load($this->reloadUser($userId), $gzip);

        self::assertSame(2, $result->entries);
        $this->em->clear();
        self::assertNotNull($this->findEntry('a'));
        self::assertNotNull($this->findEntry('b'));
    }

    public function testAnEntryStateFollowingAnAlreadyStatedOneInTheSamePartIsStillCreated(): void
    {
        $user = $this->subscribedUser(self::FEED_URL);
        $userId = $user->requireId();
        $feed = $this->feedByUrl(self::FEED_URL);
        $entryA = $this->makeEntry($feed, 'a', 'A');
        $this->makeEntry($feed, 'b', 'B');
        $this->em->flush();
        $existingState = new EntryState($user, $entryA);
        $existingState->clearFavorite();
        $this->em->persist($existingState);
        $this->em->flush();

        $gzip = $this->entryPart([
            $this->entryStateLine('a', isFavorite: true),
            $this->entryStateLine('b', isFavorite: true),
        ]);

        $result = $this->restorer()->load($this->reloadUser($userId), $gzip);

        self::assertSame(1, $result->entryStates);
        $this->em->clear();
        $entryB = $this->findEntry('b');
        self::assertNotNull($entryB);
        $stateB = $this->stateFor($this->reloadUser($userId), $entryB);
        self::assertNotNull($stateB);
        self::assertTrue($stateB->isFavorite());
    }

    public function testCreatedEntriesReachTheSearchIndex(): void
    {
        $user = $this->subscribedUser(self::FEED_URL);
        $gzip = $this->entryPart([$this->entryLine('a'), $this->entryLine('b')]);

        $this->restorer()->load($user, $gzip);

        $indexedIds = [];
        foreach ($this->indexWriter->upserts as $batch) {
            foreach ($batch as $indexedEntry) {
                $indexedIds[] = $indexedEntry->id;
            }
        }
        sort($indexedIds);

        $this->em->clear();
        $entryA = $this->findEntry('a');
        $entryB = $this->findEntry('b');
        self::assertNotNull($entryA);
        self::assertNotNull($entryB);
        $createdIds = [$entryA->requireId(), $entryB->requireId()];
        sort($createdIds);

        self::assertSame($createdIds, $indexedIds);
    }

    private function restorer(int $accountEntryCeiling = BackupFitCheck::MAX_ENTRIES): EntryPartRestorer
    {
        /** @var BackupReader $reader */
        $reader = self::getContainer()->get(BackupReader::class);
        /** @var FeedRepository $feeds */
        $feeds = self::getContainer()->get(FeedRepository::class);
        /** @var EntryRepository $entries */
        $entries = self::getContainer()->get(EntryRepository::class);
        /** @var EntryStateRepository $entryStates */
        $entryStates = self::getContainer()->get(EntryStateRepository::class);
        /** @var EntryBatchInserter $inserter */
        $inserter = self::getContainer()->get(EntryBatchInserter::class);

        $inspector = new EntryPartInspector($reader, $feeds, $entries, $accountEntryCeiling);
        $loaderFactory = new RestoreEntryLoaderFactory(
            $this->em,
            $entries,
            $entryStates,
            $inserter,
            new EntryIndexer($this->indexWriter, new NullLogger()),
            new MockClock('2026-08-20 00:00:00', 'UTC'),
            $feeds,
        );

        return new EntryPartRestorer($inspector, $reader, $loaderFactory);
    }

    private function subscribedUser(string $feedUrl): User
    {
        $user = $this->users->create($this->nextEmail());
        $feed = new Feed($feedUrl);
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01 00:00:00')));
        $this->em->flush();

        return $user;
    }

    private function subscribeTo(User $user, string $feedUrl): void
    {
        $feed = new Feed($feedUrl);
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01 00:00:00')));
        $this->em->flush();
    }

    private function nextEmail(): string
    {
        return sprintf('entry-part-restorer-%d@example.com', ++self::$userSequence);
    }

    private function feedByUrl(string $url): Feed
    {
        $feed = $this->em->getRepository(Feed::class)->findOneBy(['url' => $url]);
        self::assertInstanceOf(Feed::class, $feed);

        return $feed;
    }

    private function makeEntry(Feed $feed, string $token, string $title): Entry
    {
        $entry = new Entry(
            $feed,
            $this->guid($token),
            'https://entry-part.example/' . $token,
            $title,
            new \DateTimeImmutable('2026-08-01 00:00:00'),
            new \DateTimeImmutable('2026-08-01 00:00:00'),
        );
        $this->em->persist($entry);

        return $entry;
    }

    private function findEntry(string $token): ?Entry
    {
        /** @var Entry|null $entry */
        $entry = $this->em->createQueryBuilder()
            ->select('e')
            ->from(Entry::class, 'e')
            ->andWhere('e.guidHash = :guidHash')
            ->setParameter('guidHash', $this->guidHash($token))
            ->getQuery()
            ->getOneOrNullResult();

        return $entry;
    }

    private function stateFor(User $user, Entry $entry): ?EntryState
    {
        /** @var EntryState|null $state */
        $state = $this->em->getRepository(EntryState::class)
            ->findOneBy(['user' => $user->getId(), 'entry' => $entry->getId()]);

        return $state;
    }

    private function restoredStateOf(User $user, string $token): EntryState
    {
        $entry = $this->findEntry($token);
        self::assertNotNull($entry);
        $state = $this->stateFor($user, $entry);
        self::assertNotNull($state);

        return $state;
    }

    private function reloadUser(int $userId): User
    {
        $this->em->clear();
        $user = $this->em->find(User::class, $userId);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * @param list<int|string> $parameters
     */
    private function scalarInt(string $sql, array $parameters = []): int
    {
        $value = $this->em->getConnection()->fetchOne($sql, $parameters);
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private function guid(string $token): string
    {
        return 'guid-' . $token;
    }

    /**
     * The value RestoreFeedTarget's map is actually keyed by: the same
     * sha256(guid) an Entry computes for itself in its constructor. A file's
     * "guidHash" field and a pre-inserted row's real column must agree on
     * this for the dedupe/attach lookups under test to mean anything.
     */
    private function guidHash(string $token): string
    {
        return hash('sha256', $this->guid($token));
    }

    /** @return array<string, mixed> */
    private function entryLine(string $token, string $feedUrl = self::FEED_URL, string $title = 'Title'): array
    {
        return [
            'kind' => 'entry', 'feedUrl' => $feedUrl, 'guid' => $this->guid($token),
            'guidHash' => $this->guidHash($token), 'url' => null, 'title' => $title,
            'author' => null, 'summary' => null, 'contentHtml' => null, 'imageUrl' => null,
            'imageWidth' => null, 'imageHeight' => null, 'publishedAt' => null,
            'createdAt' => '2026-08-01T00:00:00+00:00', 'effectiveDate' => '2026-08-01T00:00:00+00:00',
        ];
    }

    /** @return array<string, mixed> */
    private function entryStateLine(string $token, string $feedUrl = self::FEED_URL, bool $isFavorite = false): array
    {
        return [
            'kind' => 'entryState', 'feedUrl' => $feedUrl, 'guidHash' => $this->guidHash($token),
            'isHidden' => false, 'isFavorite' => $isFavorite, 'isKept' => false, 'hiddenAt' => null,
            'isViewed' => false, 'viewedAt' => null,
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    private function entryPart(array $lines): string
    {
        $counts = ['entry' => 0, 'entryState' => 0];
        foreach ($lines as $line) {
            $kind = $line['kind'];
            self::assertIsString($kind);
            if (isset($counts[$kind])) {
                ++$counts[$kind];
            }
        }

        return $this->gzipOf([
            $this->entryPartHeader(),
            ...$lines,
            ['kind' => 'footer', 'counts' => $counts],
        ]);
    }

    /** @return array<string, mixed> */
    private function entryPartHeader(): array
    {
        return [
            'kind' => 'header', 'schemaVersion' => 3, 'createdAt' => '2026-08-17T09:00:00+00:00',
            'sourceUrl' => 'https://source.example', 'sourceEmail' => 'source@example.com',
            'backupId' => 'backup-1', 'part' => 1, 'parts' => null, 'totals' => null,
        ];
    }

    private function foundationGzip(): string
    {
        return $this->gzipOf([
            [
                'kind' => 'header', 'schemaVersion' => 3, 'createdAt' => '2026-08-17T09:00:00+00:00',
                'sourceUrl' => 'https://source.example', 'sourceEmail' => 'source@example.com',
                'backupId' => 'backup-1', 'part' => 0, 'parts' => 1,
                'totals' => ['entries' => 0, 'entryStates' => 0],
            ],
            ['kind' => 'account', 'locale' => 'en', 'scrapeFallbackEnabled' => false, 'magazineStyle' => 'boxed'],
            ['kind' => 'footer', 'counts' => [
                'tag' => 0, 'savedSearch' => 0, 'feed' => 0, 'subscription' => 0, 'entry' => 0, 'entryState' => 0,
            ]],
        ]);
    }

    /** @param list<array<string, mixed>> $lines */
    private function gzipOf(array $lines): string
    {
        $ndjson = implode("\n", array_map(
            static fn (array $line): string => json_encode($line, \JSON_THROW_ON_ERROR),
            $lines,
        )) . "\n";

        return (string) gzencode($ndjson);
    }
}
