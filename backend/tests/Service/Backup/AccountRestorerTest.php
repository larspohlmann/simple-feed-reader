<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Preferences;
use App\Entity\RecommendationSettings;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\SubscriptionTag;
use App\Entity\Tag;
use App\Entity\User;
use App\Exception\ValidationException;
use App\Repository\EntryRepository;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Account\AccountReset;
use App\Service\Backup\AccountBackupExporter;
use App\Service\Backup\AccountRestorer;
use App\Service\Backup\BackupFitCheck;
use App\Service\Backup\BackupInspector;
use App\Service\Backup\BackupReader;
use App\Service\Backup\EntryBatchInserter;
use App\Service\Backup\Exception\BackupDoesNotFitException;
use App\Service\Backup\Exception\InvalidBackupException;
use App\Service\Backup\RestoreLoader;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Service\Search\EntryIndexer;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\BackupFieldDeclarations;
use App\Tests\Support\FullyPopulatedAccount;
use App\Tests\Support\UserFactory;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The round trip the spec demands, driven through the real service graph: the
 * fixture files are produced by the real exporter, so a change that breaks the
 * pair breaks these tests rather than a hand-written NDJSON string that agrees
 * with neither half.
 *
 * Every assertion that a row is gone (or unchanged) runs after an explicit
 * `clear()` — AccountReset deletes with bulk DQL, so `find()` would otherwise
 * serve the stale identity map and the assertion would pass when it should
 * fail.
 */
final class AccountRestorerTest extends DbTestCase
{
    private const string ONE_URL = 'https://one.example/feed.xml';
    private const string TWO_URL = 'https://two.example/feed.xml';

    private UserFactory $users;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->users = new UserFactory($this->em, $hasher);
    }

    // ---------------------------------------------------------------- fixtures

    private function backupOf(User $user): string
    {
        $exporter = self::getContainer()->get(AccountBackupExporter::class);
        self::assertInstanceOf(AccountBackupExporter::class, $exporter);
        $ndjson = '';
        foreach ($exporter->lines($user, 'https://source.example') as $line) {
            $ndjson .= $line . "\n";
        }

        return (string) gzencode($ndjson);
    }

    private function fullyPopulatedAccount(): FullyPopulatedAccount
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return new FullyPopulatedAccount($this->em, $hasher);
    }

    private function restorer(): AccountRestorer
    {
        $restorer = self::getContainer()->get(AccountRestorer::class);
        self::assertInstanceOf(AccountRestorer::class, $restorer);

        return $restorer;
    }

    private function settingsValues(): RecommendationSettingsValues
    {
        return new RecommendationSettingsValues(
            guidancePrompt: 'Only long reads, please.',
            favoritesCap: 11,
            keptCap: 12,
            viewedCap: 13,
            candidatePoolSize: 14,
            lookbackDays: 15,
            picksLimit: 16,
            contextWindow: 17000,
            batchCount: 3,
            debugEnabled: true,
            autoGenerateIntervalHours: 8,
            showReasons: true,
        );
    }

    private function makeFeed(string $url, string $title, string $sourceFormat): Feed
    {
        $feed = new Feed($url);
        $feed->setTitle($title);
        $feed->setSiteUrl(str_replace('/feed.xml', '', $url));
        $feed->setDescription('About ' . $title);
        $feed->setFaviconUrl($url . '/favicon.ico');
        $feed->setSourceFormat($sourceFormat);
        // Fetch bookkeeping is deliberately NOT in the backup: a restored feed
        // must come back virgin, so seeding these proves the file drops them.
        $feed->setEtag('W/"seeded-etag"');
        $feed->setLastFetchedAt(new \DateTimeImmutable('2026-08-10 07:00:00'));
        $this->em->persist($feed);

        return $feed;
    }

    private function makeEntry(Feed $feed, string $guid, string $title, string $day): Entry
    {
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.test/' . $guid,
            $title,
            new \DateTimeImmutable($day . ' 06:00:00'),
            new \DateTimeImmutable($day . ' 05:00:00'),
        );
        $entry->setAuthor('An Author');
        $entry->setSummary('Summary of ' . $title);
        $entry->setContentHtml('<p>Body of ' . $title . '</p>');
        $entry->setImage('https://example.test/' . $guid . '.png', 640, 480);
        $entry->setPublishedAt(new \DateTimeImmutable($day . ' 04:00:00'));
        $this->em->persist($entry);

        return $entry;
    }

    /**
     * Two feeds, two tags with colours and per-subscription tag positions, two
     * saved searches (one whole-word), two subscriptions with a custom title
     * and a watermark, three entries with bodies and images, two entry
     * states, preferences on, a recommendation settings row and a
     * non-default locale.
     */
    private function seedRichAccount(User $user): void
    {
        $one = $this->makeFeed(self::ONE_URL, 'Original', 'xml');
        $two = $this->makeFeed(self::TWO_URL, 'Two', 'scraped');

        $tech = new Tag($user, 'Tech');
        $tech->setColor('#a1b2c3');
        $tech->setIcon('chip');
        $tech->setPosition(1);
        $this->em->persist($tech);
        $news = new Tag($user, 'News');
        $news->setColor('#c3b2a1');
        $news->setPosition(2);
        $this->em->persist($news);

        // A phrase saved search (quoted query) — its `phrase` flag must survive
        // the round trip, which `assertFieldsRoundTripped` checks below (#702).
        $this->em->persist(new SavedSearch($user, 'climate change', false, true));
        $whole = new SavedSearch($user, 'rust lang', true);
        $whole->setPosition(1);
        $this->em->persist($whole);

        $first = new Subscription($user, $one, new \DateTimeImmutable('2026-07-01 08:00:00'));
        $first->setCustomTitle('My One');
        $first->setPosition(4);
        $first->setMarkedReadUntil(new \DateTimeImmutable('2026-08-01 00:00:00'));
        $first->addTag($tech, 3);
        $first->addTag($news, 1);
        $this->em->persist($first);
        $second = new Subscription($user, $two, new \DateTimeImmutable('2026-07-02 09:00:00'));
        $second->setPosition(7);
        $this->em->persist($second);

        $entryA = $this->makeEntry($one, 'guid-a', 'Article A', '2026-08-02');
        $entryB = $this->makeEntry($one, 'guid-b', 'Article B', '2026-08-03');
        $entryC = $this->makeEntry($two, 'guid-c', 'Article C', '2026-08-04');

        $read = new EntryState($user, $entryA);
        $read->setIsHidden(true);
        $read->setIsFavorite(true);
        $read->setHiddenAt(new \DateTimeImmutable('2026-08-05 10:00:00'));
        $this->em->persist($read);
        $viewed = new EntryState($user, $entryC);
        $viewed->setIsKept(true);
        $viewed->markViewed(new \DateTimeImmutable('2026-08-06 11:00:00'));
        $this->em->persist($viewed);

        $user->setLocale('de');
        $user->getPreferences()->setScrapeFallbackEnabled(true);
        $settings = new RecommendationSettings($user);
        $settings->update($this->settingsValues());
        $this->em->persist($settings);

        $this->em->flush();
        self::assertNotNull($entryB->getId());
    }

    private function seededUser(string $email): User
    {
        $user = $this->users->create($email);
        $this->seedRichAccount($user);

        return $user;
    }

    // ------------------------------------------------------------- assertions

    private function deleteEveryFeed(): void
    {
        // feed cascades to subscription and entry, entry cascades to
        // entry_state — one statement empties the shared half of the schema.
        $this->em->getConnection()->executeStatement('DELETE FROM feed');
        $this->em->clear();
    }

    /**
     * @param list<int|string> $parameters
     */
    private function scalarInt(string $sql, array $parameters = []): int
    {
        return self::asInt($this->em->getConnection()->fetchOne($sql, $parameters));
    }

    private static function asInt(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private static function asString(mixed $value): string
    {
        self::assertIsScalar($value);

        return (string) $value;
    }

    private function reloadUser(int $userId): User
    {
        $this->em->clear();
        $user = $this->em->find(User::class, $userId);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /** @return list<Subscription> */
    private function subscriptionsOf(int $userId): array
    {
        $repository = self::getContainer()->get(SubscriptionRepository::class);
        self::assertInstanceOf(SubscriptionRepository::class, $repository);

        return $repository->findForUserWithTags($userId);
    }

    /** @return array<string, array<string, mixed>> */
    private function subscriptionShapes(int $userId): array
    {
        $shapes = [];
        foreach ($this->subscriptionsOf($userId) as $subscription) {
            $tags = [];
            foreach ($subscription->getSubscriptionTags() as $subscriptionTag) {
                self::assertInstanceOf(SubscriptionTag::class, $subscriptionTag);
                $tags[$subscriptionTag->getTag()->getName()] = $subscriptionTag->getPosition();
            }
            ksort($tags);
            $shapes[$subscription->getFeed()->getUrl()] = [
                'customTitle' => $subscription->getCustomTitle(),
                'position' => $subscription->getPosition(),
                'markedReadUntil' => $subscription->getMarkedReadUntil()?->format('Y-m-d H:i:s'),
                'createdAt' => $subscription->getCreatedAt()->format('Y-m-d H:i:s'),
                'tags' => $tags,
            ];
        }
        ksort($shapes);

        return $shapes;
    }

    /** @return list<array<string, mixed>> */
    private function entryStateRows(int $userId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT e.guid, s.is_hidden, s.is_favorite, s.is_kept, s.hidden_at, s.is_viewed, s.viewed_at'
            . ' FROM entry_state s JOIN entry e ON e.id = s.entry_id WHERE s.user_id = ? ORDER BY e.guid',
            [$userId],
        );

        return $rows;
    }

    /**
     * The entry table as the restore left it, read through the connection so
     * the assertions see the STORED values — guid_hash above all, which the
     * loader writes from the file rather than recomputing.
     *
     * @return list<array{guid: string, guidHash: string, contentHtml: string,
     *                    createdAt: string, effectiveDate: string, imageWidth: int}>
     */
    private function entryRows(): array
    {
        $rows = [];
        $selected = $this->em->getConnection()->fetchAllAssociative(
            'SELECT guid, guid_hash, content_html, created_at, effective_date, image_width'
            . ' FROM entry ORDER BY guid',
        );
        foreach ($selected as $row) {
            $rows[] = [
                'guid' => self::asString($row['guid']),
                'guidHash' => self::asString($row['guid_hash']),
                'contentHtml' => self::asString($row['content_html']),
                'createdAt' => self::asString($row['created_at']),
                'effectiveDate' => self::asString($row['effective_date']),
                'imageWidth' => self::asInt($row['image_width']),
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------------ tests

    public function testRoundTripReproducesTheAccountFieldForField(): void
    {
        $user = $this->seededUser('roundtrip@example.com');
        $userId = (int) $user->getId();
        $gzip = $this->backupOf($user);
        $before = $this->subscriptionShapes($userId);
        $statesBefore = $this->entryStateRows($userId);

        $result = $this->restorer()->restore($this->reloadUser($userId), $gzip, 'REPLACE');

        self::assertSame(2, $result->tags);
        self::assertSame(2, $result->savedSearches);
        // Both feeds and all three entries already exist as shared rows, so a
        // same-instance restore creates neither.
        self::assertSame(0, $result->feeds);
        self::assertSame(0, $result->entries);
        self::assertSame(2, $result->subscriptions);
        self::assertSame(2, $result->entryStates);

        $restored = $this->reloadUser($userId);
        self::assertSame('de', $restored->getLocale());
        self::assertTrue($restored->getPreferences()->isScrapeFallbackEnabled());

        $settings = $this->em->getRepository(RecommendationSettings::class)
            ->findOneBy(['user' => $userId]);
        self::assertInstanceOf(RecommendationSettings::class, $settings);
        self::assertEquals($this->settingsValues(), $settings->values());

        $tags = $this->em->getRepository(Tag::class)->findBy(['user' => $userId], ['name' => 'ASC']);
        self::assertCount(2, $tags);
        $tagShapes = array_map(
            static fn (Tag $tag): array => [$tag->getName(), $tag->getColor(), $tag->getIcon(), $tag->getPosition()],
            $tags,
        );
        self::assertSame(
            [['News', '#c3b2a1', null, 2], ['Tech', '#a1b2c3', 'chip', 1]],
            $tagShapes,
        );

        self::assertSame($before, $this->subscriptionShapes($userId));
        self::assertSame($statesBefore, $this->entryStateRows($userId));
        self::assertSame(3, $this->scalarInt('SELECT COUNT(*) FROM entry'));
    }

    /**
     * Closes the gap the write-direction guard leaves open: a field declared
     * `BACKED_UP` and written by the exporter can still be lost if the Line
     * DTO that reads the file back never claims it. `BackupSchemaCoverageTest`
     * proves the write half; this proves the read half, off the identical
     * `BackupFieldDeclarations::BACKED_UP` list, so it grows the moment that
     * one does rather than needing a matching edit here (#556).
     *
     * The account restored onto is a fresh one, not the account that made the
     * file: `RestoreLoadPass::loadFeed()` leaves a Feed row untouched when one
     * with the same URL already exists, and the source account's own feed
     * would be exactly such a row. Comparing against a row this restore
     * merely referenced, rather than one it wrote from the file's fields,
     * would let the bug this test exists to catch pass unnoticed.
     */
    public function testEveryBackedUpFieldSurvivesTheRestoreRoundTrip(): void
    {
        $source = $this->fullyPopulatedAccount()->create('drift-source@example.com');
        $gzip = $this->backupOf($source);
        $sourceRows = $this->fixtureRowsOf($source);

        $target = $this->users->create('drift-target@example.com');
        $targetId = (int) $target->getId();
        $this->deleteEveryFeed();

        $this->restorer()->restore($this->reloadUser($targetId), $gzip, 'REPLACE');
        $targetRows = $this->fixtureRowsOf($this->reloadUser($targetId));

        $this->assertFieldsRoundTripped(
            User::class,
            $sourceRows['user'],
            $targetRows['user'],
            ['recommendationSettings'],
        );
        $this->assertFieldsRoundTripped(Preferences::class, $sourceRows['preferences'], $targetRows['preferences']);
        $this->assertRecommendationSettingsRoundTripped($sourceRows['settings'], $targetRows['settings']);
        $this->assertFieldsRoundTripped(Tag::class, $sourceRows['tag'], $targetRows['tag']);
        $this->assertFieldsRoundTripped(SavedSearch::class, $sourceRows['savedSearch'], $targetRows['savedSearch']);
        $this->assertFieldsRoundTripped(Feed::class, $sourceRows['feed'], $targetRows['feed']);

        $this->assertFieldsRoundTripped(
            Subscription::class,
            $sourceRows['subscription'],
            $targetRows['subscription'],
            ['feed', 'subscriptionTags'],
        );
        self::assertSame($sourceRows['feed']->getUrl(), $targetRows['subscription']->getFeed()->getUrl());
        self::assertSame(
            $this->tagAssignments($sourceRows['subscription']),
            $this->tagAssignments($targetRows['subscription']),
        );

        $this->assertFieldsRoundTripped(
            SubscriptionTag::class,
            $sourceRows['subscriptionTag'],
            $targetRows['subscriptionTag'],
            ['tag'],
        );
        self::assertSame(
            $sourceRows['subscriptionTag']->getTag()->getName(),
            $targetRows['subscriptionTag']->getTag()->getName(),
        );

        $this->assertFieldsRoundTripped(Entry::class, $sourceRows['entry'], $targetRows['entry'], ['feed']);
        self::assertSame($sourceRows['feed']->getUrl(), $targetRows['entry']->getFeed()->getUrl());

        $this->assertFieldsRoundTripped(
            EntryState::class,
            $sourceRows['entryState'],
            $targetRows['entryState'],
            ['entry'],
        );
        $restoredEntry = $targetRows['entryState']->getEntry();
        self::assertSame(
            [$sourceRows['feed']->getUrl(), $sourceRows['entry']->getGuidHash()],
            [$restoredEntry->getFeed()->getUrl(), $restoredEntry->getGuidHash()],
        );
    }

    /**
     * One of every kind of row `FullyPopulatedAccount` seeds for $user, fetched
     * fresh so a caller can compare a source account's rows against a restored
     * account's rows field by field.
     *
     * @return array{user: User, preferences: Preferences, settings: RecommendationSettings,
     *     tag: Tag, savedSearch: SavedSearch, feed: Feed, subscription: Subscription,
     *     subscriptionTag: SubscriptionTag, entry: Entry, entryState: EntryState}
     */
    private function fixtureRowsOf(User $user): array
    {
        $userId = (int) $user->getId();

        $subscription = $this->em->getRepository(Subscription::class)->findOneBy(['user' => $userId]);
        self::assertInstanceOf(Subscription::class, $subscription);
        $subscriptionTags = $subscription->getSubscriptionTags();
        self::assertCount(1, $subscriptionTags);
        $subscriptionTag = reset($subscriptionTags);
        self::assertInstanceOf(SubscriptionTag::class, $subscriptionTag);

        // A ManyToOne association loads lazily: without touching it here, the
        // caller's later getUrl() call would try to initialize this proxy for
        // the first time AFTER the source account's own feed row was deleted
        // to force the target's rows to build fresh from the file — and find
        // nothing to load. Touching it now, while the row still exists, bakes
        // the value into the object so it survives that deletion detached.
        $feed = $subscription->getFeed();
        $feed->getUrl();

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['feed' => $feed]);
        self::assertInstanceOf(Entry::class, $entry);
        $entryState = $this->em->getRepository(EntryState::class)->findOneBy(['user' => $userId]);
        self::assertInstanceOf(EntryState::class, $entryState);
        $tag = $this->em->getRepository(Tag::class)->findOneBy(['user' => $userId]);
        self::assertInstanceOf(Tag::class, $tag);
        $savedSearch = $this->em->getRepository(SavedSearch::class)->findOneBy(['user' => $userId]);
        self::assertInstanceOf(SavedSearch::class, $savedSearch);
        $settings = $this->em->getRepository(RecommendationSettings::class)->findOneBy(['user' => $userId]);
        self::assertInstanceOf(RecommendationSettings::class, $settings);

        return [
            'user' => $user,
            'preferences' => $user->getPreferences(),
            'settings' => $settings,
            'tag' => $tag,
            'savedSearch' => $savedSearch,
            'feed' => $feed,
            'subscription' => $subscription,
            'subscriptionTag' => $subscriptionTag,
            'entry' => $entry,
            'entryState' => $entryState,
        ];
    }

    /**
     * Every field `BackupFieldDeclarations::BACKED_UP` declares for
     * $entityClass, minus $handledSeparately, read through the entity's own
     * getter and compared before vs after the restore.
     *
     * Generic on purpose: a newly `BACKED_UP` scalar field needs no new case
     * here, only a getter — which is exactly what BackupSchemaCoverageTest's
     * write-only proof could not force (#556). $handledSeparately exists for
     * the small, fixed set of association fields (`feed`, `subscriptionTags`,
     * `tag`, `entry`) whose comparison is a relationship, not a getter call —
     * the caller asserts those itself.
     *
     * @param list<string> $handledSeparately
     */
    private function assertFieldsRoundTripped(
        string $entityClass,
        object $source,
        object $target,
        array $handledSeparately = [],
    ): void {
        $fields = array_diff(array_keys(BackupFieldDeclarations::BACKED_UP[$entityClass]), $handledSeparately);
        foreach ($fields as $field) {
            self::assertEquals(
                $this->getterValue($source, $field),
                $this->getterValue($target, $field),
                sprintf(
                    '%s::$%s did not survive the export/restore round trip: the exporter '
                    . 'writes it, but no Line DTO reads it back on restore.',
                    $entityClass,
                    $field,
                ),
            );
        }
    }

    private function assertRecommendationSettingsRoundTripped(
        RecommendationSettings $source,
        RecommendationSettings $target,
    ): void {
        foreach (array_keys(BackupFieldDeclarations::BACKED_UP[RecommendationSettings::class]) as $field) {
            self::assertEquals(
                $source->values()->{$field},
                $target->values()->{$field},
                sprintf(
                    'RecommendationSettings::$%s did not survive the export/restore round trip.',
                    $field,
                ),
            );
        }
    }

    /**
     * Reads a declared field off an entity through its own getter, deriving
     * the method name from the field's own path so an embedded field such as
     * `image.url` finds `getImageUrl()` the same way a plain field finds
     * `getUrl()`. Tried bare first, because a boolean field already named
     * `isHidden` or `isKept` has a getter of that exact name, not `isIsRead()`.
     */
    private function getterValue(object $entity, string $field): mixed
    {
        $pascal = implode('', array_map(ucfirst(...), explode('.', $field)));
        foreach ([lcfirst($pascal), 'get' . $pascal, 'is' . $pascal, 'has' . $pascal] as $method) {
            if (method_exists($entity, $method)) {
                return $entity->$method();
            }
        }

        throw new \LogicException(sprintf(
            '%s has no getter for backed-up field "%s". Give it one, or add "%s" to the '
            . 'handledSeparately list in %s and assert it by hand.',
            $entity::class,
            $field,
            $field,
            self::class,
        ));
    }

    /** @return array<string, int> tag name => position on the subscription */
    private function tagAssignments(Subscription $subscription): array
    {
        $assignments = [];
        foreach ($subscription->getSubscriptionTags() as $subscriptionTag) {
            self::assertInstanceOf(SubscriptionTag::class, $subscriptionTag);
            $assignments[$subscriptionTag->getTag()->getName()] = $subscriptionTag->getPosition();
        }
        ksort($assignments);

        return $assignments;
    }

    public function testRestoreOntoAnEmptyInstanceCreatesFeedsAndEntries(): void
    {
        $user = $this->seededUser('empty-instance@example.com');
        $userId = (int) $user->getId();
        $gzip = $this->backupOf($user);
        $before = $this->subscriptionShapes($userId);
        $statesBefore = $this->entryStateRows($userId);
        $this->deleteEveryFeed();

        $result = $this->restorer()->restore($this->reloadUser($userId), $gzip, 'REPLACE');

        self::assertSame(2, $result->feeds);
        self::assertSame(3, $result->entries);
        self::assertSame(2, $result->subscriptions);
        self::assertSame(2, $result->tags);
        self::assertSame(2, $result->savedSearches);
        self::assertSame(2, $result->entryStates);

        $this->em->clear();
        $feeds = self::getContainer()->get(FeedRepository::class);
        self::assertInstanceOf(FeedRepository::class, $feeds);
        $one = $feeds->findOneBy(['url' => self::ONE_URL]);
        self::assertInstanceOf(Feed::class, $one);
        self::assertSame('Original', $one->getTitle());
        self::assertSame('About Original', $one->getDescription());
        self::assertSame('xml', $one->getSourceFormat());
        // The backup carries no fetch bookkeeping, so a recreated feed is
        // virgin and the next refresh treats it as never fetched.
        self::assertNull($one->getLastFetchedAt());
        self::assertNull($one->getEtag());
        $two = $feeds->findOneBy(['url' => self::TWO_URL]);
        self::assertInstanceOf(Feed::class, $two);
        self::assertSame('scraped', $two->getSourceFormat());

        $rows = $this->entryRows();
        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertSame(hash('sha256', $row['guid']), $row['guidHash']);
            self::assertSame(640, $row['imageWidth']);
        }
        self::assertSame('<p>Body of Article A</p>', $rows[0]['contentHtml']);
        self::assertStringStartsWith('2026-08-02 06:00:00', $rows[0]['createdAt']);
        self::assertStringStartsWith('2026-08-02 05:00:00', $rows[0]['effectiveDate']);

        self::assertSame($before, $this->subscriptionShapes($userId));
        self::assertSame($statesBefore, $this->entryStateRows($userId));
    }

    public function testEntriesAreNotCreatedIntoAFeedAnotherUserReads(): void
    {
        $user = $this->seededUser('shared-entries@example.com');
        $userId = (int) $user->getId();
        $gzip = $this->backupOf($user);
        $feedId = $this->scalarInt('SELECT id FROM feed WHERE url = ?', [self::ONE_URL]);
        $this->em->getConnection()->executeStatement('DELETE FROM entry WHERE feed_id = ?', [$feedId]);
        $this->em->clear();

        $stranger = $this->users->create('stranger@example.com');
        $feed = $this->em->find(Feed::class, $feedId);
        self::assertInstanceOf(Feed::class, $feed);
        $this->em->persist(new Subscription($stranger, $feed, new \DateTimeImmutable('2026-07-03 10:00:00')));
        $this->em->flush();

        $result = $this->restorer()->restore($this->reloadUser($userId), $gzip, 'REPLACE');

        // Feed TWO's entries survive untouched (nobody else reads it), so the
        // only entries the file could have created are feed ONE's — and the
        // stranger's unread list forbids them.
        self::assertSame(0, $result->entries);
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM entry WHERE feed_id = ?', [$feedId]));
        self::assertArrayHasKey(self::ONE_URL, $this->subscriptionShapes($userId));
    }

    public function testAFeedRowAnotherUserReadsIsNotModified(): void
    {
        $user = $this->seededUser('shared-feed@example.com');
        $userId = (int) $user->getId();
        $gzip = $this->backupOf($user);
        $feedId = $this->scalarInt('SELECT id FROM feed WHERE url = ?', [self::ONE_URL]);
        $this->em->clear();

        $stranger = $this->users->create('feed-stranger@example.com');
        $feed = $this->em->find(Feed::class, $feedId);
        self::assertInstanceOf(Feed::class, $feed);
        $feed->setTitle('Theirs');
        $this->em->persist(new Subscription($stranger, $feed, new \DateTimeImmutable('2026-07-03 10:00:00')));
        $this->em->flush();

        $this->restorer()->restore($this->reloadUser($userId), $gzip, 'REPLACE');

        $this->em->clear();
        $after = $this->em->find(Feed::class, $feedId);
        self::assertInstanceOf(Feed::class, $after);
        self::assertSame('Theirs', $after->getTitle());
    }

    public function testRefusalHappensBeforeAnyDeletion(): void
    {
        $source = $this->seededUser('fit-source@example.com');
        $gzip = $this->backupOf($source);

        $target = $this->users->create('fit-target@example.com', maxSubscriptions: 1);
        $this->seedRichAccountForCappedTarget($target);
        $targetId = (int) $target->getId();

        try {
            $this->restorer()->restore($this->reloadUser($targetId), $gzip, 'REPLACE');
            self::fail('The restore accepted a backup that does not fit the account.');
        } catch (BackupDoesNotFitException) {
            // Expected — and nothing may have been deleted by now.
        }

        $this->em->clear();
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM tag WHERE user_id = ?', [$targetId]));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM subscription WHERE user_id = ?', [$targetId]));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM entry_state WHERE user_id = ?', [$targetId]));
    }

    public function testWithoutTheConfirmationNothingHappens(): void
    {
        $user = $this->seededUser('unconfirmed@example.com');
        $userId = (int) $user->getId();
        $gzip = $this->backupOf($user);

        try {
            $this->restorer()->restore($this->reloadUser($userId), $gzip, null);
            self::fail('The restore ran without the REPLACE confirmation.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('confirm', $e->errors);
        }

        $this->em->clear();
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM tag WHERE user_id = ?', [$userId]));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM subscription WHERE user_id = ?', [$userId]));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM entry_state WHERE user_id = ?', [$userId]));
    }

    public function testARestoreCanBeRerunAfterItself(): void
    {
        $user = $this->seededUser('rerun@example.com');
        $userId = (int) $user->getId();
        $gzip = $this->backupOf($user);
        $before = $this->subscriptionShapes($userId);
        $statesBefore = $this->entryStateRows($userId);
        $this->deleteEveryFeed();

        $this->restorer()->restore($this->reloadUser($userId), $gzip, 'REPLACE');
        $second = $this->restorer()->restore($this->reloadUser($userId), $gzip, 'REPLACE');

        // The second run finds every shared row already in place, so it
        // re-creates only what the wipe removed.
        self::assertSame(0, $second->feeds);
        self::assertSame(0, $second->entries);
        self::assertSame(2, $second->tags);
        self::assertSame(2, $second->savedSearches);
        self::assertSame(2, $second->subscriptions);
        self::assertSame(2, $second->entryStates);

        $this->em->clear();
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM feed'));
        self::assertSame(3, $this->scalarInt('SELECT COUNT(*) FROM entry'));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM tag WHERE user_id = ?', [$userId]));
        self::assertSame($before, $this->subscriptionShapes($userId));
        self::assertSame($statesBefore, $this->entryStateRows($userId));
        self::assertSame('de', $this->reloadUser($userId)->getLocale());
    }

    // testAFileTheDatabaseRejectsFailsAsATypedBackupError used to live here,
    // staging a duplicate tag (then a duplicate subscription) to reach
    // RestoreLoadPass's flush()-catch(DbalException) branch through content.
    // #412's final review closed that route for good: the inspector now
    // refuses a duplicate tag, feed or subscription in pass 1;
    // RestoreEntryLoader::unknownOf() already dropped a repeated entry line
    // by design; and a repeated entry_state line collides in Doctrine's own
    // identity map before it ever reaches SQL. No content this restorer can
    // be handed reaches that branch any more — corrupting the schema to
    // force it breaks the MySQL leg's transactional test isolation (DDL
    // commits implicitly), so the proof cannot live here either.
    // RestoreLoadPassTest::testADatabaseFailureDuringTheAccountShapeFlushIsAWrappedBackupError
    // proves the wrap directly, with a fake EntityManager standing in for
    // the driver failure this test used to stage through content.

    /** @return array<string, int> */
    private function withCountShiftedBy(string $kind, int $delta, mixed $counts): array
    {
        self::assertIsArray($counts);
        $corrected = [];
        foreach ($counts as $countedKind => $count) {
            self::assertIsString($countedKind);
            $corrected[$countedKind] = self::asInt($count);
        }
        $corrected[$kind] += $delta;

        return $corrected;
    }

    /**
     * The headline property of the whole feature: a file that cannot be fully
     * accepted costs the account nothing. A dangling reference used to be
     * found only during the load, with the wipe already behind it.
     */
    public function testAReferentialRefusalLeavesEveryRowInPlace(): void
    {
        $user = $this->seededUser('dangling@example.com');
        $userId = (int) $user->getId();
        $gzip = $this->withoutTheFirstFeedLine($this->backupOf($user));

        try {
            $this->restorer()->restore($this->reloadUser($userId), $gzip, 'REPLACE');
            self::fail('The restore accepted a subscription whose feed the file never declares.');
        } catch (InvalidBackupException) {
            // Expected — and nothing may have been deleted by now.
        }

        $this->assertTheSeededAccountSurvived($userId);
    }

    private function assertTheSeededAccountSurvived(int $userId): void
    {
        $this->em->clear();
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM tag WHERE user_id = ?', [$userId]));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM subscription WHERE user_id = ?', [$userId]));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM entry_state WHERE user_id = ?', [$userId]));
    }

    /**
     * Drops one feed line and corrects the footer, so the file's grammar is
     * intact and only its subscriptions are left pointing at nothing.
     */
    private function withoutTheFirstFeedLine(string $gzip): string
    {
        $lines = [];
        $dropped = false;
        foreach ($this->decodedLinesOf($gzip) as $decoded) {
            $kind = $decoded['kind'] ?? null;
            if (!$dropped && 'feed' === $kind) {
                $dropped = true;
                continue;
            }
            if ('footer' === $kind) {
                $decoded['counts'] = $this->withCountShiftedBy('feed', -1, $decoded['counts']);
            }
            $lines[] = json_encode($decoded, \JSON_THROW_ON_ERROR);
        }
        self::assertTrue($dropped, 'the fixture account must carry at least one feed');

        return (string) gzencode(implode("\n", $lines) . "\n");
    }

    /** @return list<array<string, mixed>> */
    private function decodedLinesOf(string $gzip): array
    {
        $decodedLines = [];
        foreach (explode("\n", (string) gzdecode($gzip)) as $line) {
            if ('' === $line) {
                continue;
            }
            $decoded = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            /** @var array<string, mixed> $decoded */
            $decodedLines[] = $decoded;
        }

        return $decodedLines;
    }

    public function testRestoredEntriesReachTheSearchIndex(): void
    {
        $user = $this->seededUser('indexed@example.com');
        $userId = (int) $user->getId();
        $gzip = $this->backupOf($user);
        $this->deleteEveryFeed();

        $writer = new RecordingSearchIndexWriter();
        $result = $this->restorerIndexingInto($writer)->restore($this->reloadUser($userId), $gzip, 'REPLACE');

        self::assertSame(3, $result->entries);
        $indexed = [];
        foreach ($writer->upserts as $batch) {
            foreach ($batch as $document) {
                $indexed[] = $document->id;
            }
        }
        sort($indexed);

        $created = [];
        foreach ($this->em->getConnection()->fetchFirstColumn('SELECT id FROM entry ORDER BY id') as $id) {
            $created[] = self::asInt($id);
        }
        self::assertCount(3, $created);
        self::assertSame($created, $indexed);
    }

    // -------------------------------------------------------------- test seams

    /**
     * The search engine is unconfigured in the test environment by design
     * (phpunit.dist.xml forces MEILISEARCH_URL/KEY empty, guarded by
     * SearchEngineDisabledInTestEnvironmentTest), so the only honest seam is
     * the one EntryIndexerTest itself uses: a real EntryIndexer over a
     * RecordingSearchIndexWriter. Everything else in the graph stays the
     * container's own service.
     */
    private function restorerIndexingInto(RecordingSearchIndexWriter $writer): AccountRestorer
    {
        $entries = $this->em->getRepository(Entry::class);
        self::assertInstanceOf(EntryRepository::class, $entries);
        $feeds = $this->em->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $feeds);
        $inserter = self::getContainer()->get(EntryBatchInserter::class);
        self::assertInstanceOf(EntryBatchInserter::class, $inserter);
        $fitCheck = self::getContainer()->get(BackupFitCheck::class);
        self::assertInstanceOf(BackupFitCheck::class, $fitCheck);
        $reset = self::getContainer()->get(AccountReset::class);
        self::assertInstanceOf(AccountReset::class, $reset);

        $loader = new RestoreLoader(
            $this->em,
            new BackupReader(),
            $feeds,
            $entries,
            $inserter,
            new EntryIndexer($writer, new NullLogger()),
            new MockClock('2026-08-17 12:00:00'),
        );

        return new AccountRestorer($this->em, new BackupInspector(new BackupReader()), $fitCheck, $reset, $loader);
    }

    /** One tag, one subscription and one entry state, so the refusal has something to protect. */
    private function seedRichAccountForCappedTarget(User $user): void
    {
        $feed = new Feed('https://capped.example/feed.xml');
        $this->em->persist($feed);
        $tag = new Tag($user, 'Kept');
        $this->em->persist($tag);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-04 08:00:00'));
        $subscription->addTag($tag, 0);
        $this->em->persist($subscription);
        $entry = $this->makeEntry($feed, 'guid-capped', 'Capped', '2026-08-07');
        $this->em->persist(new EntryState($user, $entry));
        $this->em->flush();
    }
}
