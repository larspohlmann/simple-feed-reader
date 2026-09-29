<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Preferences;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\SubscriptionTag;
use App\Entity\Tag;
use App\Entity\User;
use App\Exception\ValidationException;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Backup\AccountBackupExporter;
use App\Service\Backup\AccountRestorer;
use App\Service\Backup\EntryPartRestorer;
use App\Service\Backup\Exception\BackupDoesNotFitException;
use App\Service\Backup\Exception\InvalidBackupException;
use App\Service\Search\SavedSearchSlug;
use App\Tests\DbTestCase;
use App\Tests\Support\BackupFieldDeclarations;
use App\Tests\Support\FullyPopulatedAccount;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The round trip through the real service graph, on files the real exporter produced. Every "row is gone" assertion
 * runs after clear(): AccountReset deletes with bulk DQL, so find() would serve the stale identity map.
 */
final class AccountRestorerTest extends DbTestCase
{
    use ReloadsEntities;

    private const string ONE_URL = 'https://one.example/feed.xml';
    private const string TWO_URL = 'https://two.example/feed.xml';
    private const string FOUNDATION_FEED_URL = 'https://foundation.example/feed.xml';

    private UserFactory $userFactory;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->userFactory = new UserFactory($this->entityManager, $hasher);
    }

    /** The foundation part of a real export, the only part `start()` reads. */
    private function backupOf(User $user): string
    {
        $exporter = self::getContainer()->get(AccountBackupExporter::class);
        self::assertInstanceOf(AccountBackupExporter::class, $exporter);
        foreach ($exporter->parts($user, 'https://source.example') as $part) {
            if ('000-foundation.ndjson.gz' === $part->memberName) {
                return $part->gzipBytes;
            }
        }

        throw new \LogicException('The exporter produced no foundation part.');
    }

    /**
     * Every entry part of a real export, in member-name order — the parts
     * `start()` never reads and `EntryPartRestorer::load()` loads one at a
     * time.
     *
     * @return list<string>
     */
    private function entryPartsOf(User $user): array
    {
        $exporter = self::getContainer()->get(AccountBackupExporter::class);
        self::assertInstanceOf(AccountBackupExporter::class, $exporter);
        $parts = [];
        foreach ($exporter->parts($user, 'https://source.example') as $part) {
            if ('000-foundation.ndjson.gz' !== $part->memberName) {
                $parts[$part->memberName] = $part->gzipBytes;
            }
        }
        ksort($parts);

        return array_values($parts);
    }

    private function entryPartRestorer(): EntryPartRestorer
    {
        $restorer = self::getContainer()->get(EntryPartRestorer::class);
        self::assertInstanceOf(EntryPartRestorer::class, $restorer);

        return $restorer;
    }

    private function accountWithOneSubscription(): User
    {
        $user = $this->userFactory->create('one-subscription@example.com');
        $feed = new Feed('https://kept.example/feed.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01 00:00:00')));
        $this->entityManager->flush();

        return $user;
    }

    private function emptyAccount(): User
    {
        return $this->userFactory->create('empty-account@example.com');
    }

    private function subscriptionCount(User $user): int
    {
        return $this->scalarInt('SELECT COUNT(*) FROM subscription WHERE user_id = ?', [$user->requireId()]);
    }

    /**
     * @param array{entries?: int, entryStates?: int} $totals
     */
    private function foundationGzip(array $totals = ['entries' => 0, 'entryStates' => 0]): string
    {
        return $this->gzipOf([
            $this->headerLine(part: 0, parts: 1, totals: $totals),
            ['kind' => 'account', 'locale' => 'en', 'scrapeFallbackEnabled' => false, 'magazineStyle' => 'boxed'],
            ['kind' => 'tag', 'name' => 'Tech', 'color' => null, 'icon' => null, 'position' => 0],
            ['kind' => 'savedSearch', 'term' => 'rust', 'wholeWord' => false, 'phrase' => false, 'position' => 0],
            ['kind' => 'feed', 'url' => self::FOUNDATION_FEED_URL, 'siteUrl' => null, 'title' => null,
                'description' => null, 'faviconUrl' => null, 'imageUrl' => null, 'sourceFormat' => 'xml'],
            ['kind' => 'subscription', 'feedUrl' => self::FOUNDATION_FEED_URL, 'customTitle' => null,
                'position' => 0, 'markedReadUntil' => null, 'createdAt' => '2026-07-01T00:00:00+00:00',
                'tags' => [], 'includeInAllItems' => true, 'includeInForYou' => true],
            ['kind' => 'footer', 'counts' => [
                'tag' => 1, 'savedSearch' => 1, 'feed' => 1, 'subscription' => 1, 'entry' => 0, 'entryState' => 0,
            ]],
        ]);
    }

    private function entryPartGzip(): string
    {
        return $this->gzipOf([
            $this->headerLine(part: 1, parts: null, totals: null),
            ['kind' => 'entry', 'feedUrl' => self::FOUNDATION_FEED_URL, 'guid' => 'g', 'guidHash' => 'h',
                'url' => null, 'title' => 'One', 'author' => null, 'summary' => null, 'contentHtml' => null,
                'imageUrl' => null, 'imageWidth' => null, 'imageHeight' => null, 'publishedAt' => null,
                'createdAt' => '2026-08-01T00:00:00+00:00', 'effectiveDate' => '2026-08-01T00:00:00+00:00'],
            ['kind' => 'footer', 'counts' => ['entry' => 1, 'entryState' => 0]],
        ]);
    }

    /**
     * @param array{entries?: int, entryStates?: int}|null $totals
     *
     * @return array<string, mixed>
     */
    private function headerLine(int $part, ?int $parts, ?array $totals): array
    {
        return [
            'kind' => 'header',
            'schemaVersion' => 3,
            'createdAt' => '2026-08-17T09:00:00+00:00',
            'sourceUrl' => 'https://source.example',
            'sourceEmail' => 'source@example.com',
            'backupId' => 'backup-1',
            'part' => $part,
            'parts' => $parts,
            'totals' => null === $totals
                ? null
                : ['entries' => $totals['entries'] ?? 0, 'entryStates' => $totals['entryStates'] ?? 0],
        ];
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

    private function fullyPopulatedAccount(): FullyPopulatedAccount
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return new FullyPopulatedAccount($this->entityManager, $hasher);
    }

    private function restorer(): AccountRestorer
    {
        $restorer = self::getContainer()->get(AccountRestorer::class);
        self::assertInstanceOf(AccountRestorer::class, $restorer);

        return $restorer;
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
        $feed->recordCacheValidators('W/"seeded-etag"', null);
        $feed->recordSuccessfulFetch(new \DateTimeImmutable('2026-08-10 07:00:00'), 60);
        $this->entityManager->persist($feed);

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
        $entry->getImage()->storePending('https://example.test/' . $guid . '.png', 640, 480);
        $entry->setPublishedAt(new \DateTimeImmutable($day . ' 04:00:00'));
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function seedRichAccount(User $user): void
    {
        $one = $this->makeFeed(self::ONE_URL, 'Original', 'xml');
        $two = $this->makeFeed(self::TWO_URL, 'Two', 'scraped');

        $tech = new Tag($user, 'Tech');
        $tech->setColor('#a1b2c3');
        $tech->setIcon('chip');
        $tech->setPosition(1);
        $this->entityManager->persist($tech);
        $news = new Tag($user, 'News');
        $news->setColor('#c3b2a1');
        $news->setPosition(2);
        $this->entityManager->persist($news);

        // A phrase saved search (quoted query) — its `phrase` flag must survive
        // the round trip, which `assertFieldsRoundTripped` checks below.
        $this->entityManager->persist(new SavedSearch($user, 'climate change', false, true));
        $whole = new SavedSearch($user, 'rust lang', true);
        $whole->setPosition(1);
        $this->entityManager->persist($whole);

        $first = new Subscription($user, $one, new \DateTimeImmutable('2026-07-01 08:00:00'));
        $first->setCustomTitle('My One');
        $first->setPosition(4);
        $first->setMarkedReadUntil(new \DateTimeImmutable('2026-08-01 00:00:00'));
        $first->addTag($tech, 3);
        $first->addTag($news, 1);
        $this->entityManager->persist($first);
        $second = new Subscription($user, $two, new \DateTimeImmutable('2026-07-02 09:00:00'));
        $second->setPosition(7);
        $this->entityManager->persist($second);

        $entryA = $this->makeEntry($one, 'guid-a', 'Article A', '2026-08-02');
        $entryB = $this->makeEntry($one, 'guid-b', 'Article B', '2026-08-03');
        $entryC = $this->makeEntry($two, 'guid-c', 'Article C', '2026-08-04');

        $read = new EntryState($user, $entryA);
        $read->hide(new \DateTimeImmutable('2026-08-05 10:00:00'));
        $read->markFavorite();
        $this->entityManager->persist($read);
        $viewed = new EntryState($user, $entryC);
        $viewed->markKept();
        $viewed->markViewed(new \DateTimeImmutable('2026-08-06 11:00:00'));
        $this->entityManager->persist($viewed);

        $user->setLocale('de');
        $user->getPreferences()->setScrapeFallbackEnabled(true);

        $this->entityManager->flush();
        self::assertNotNull($entryB->getId());
    }

    private function seededUser(string $email): User
    {
        $user = $this->userFactory->create($email);
        $this->seedRichAccount($user);

        return $user;
    }

    private function deleteEveryFeed(): void
    {
        // feed cascades to subscription and entry, entry cascades to
        // entry_state — one statement empties the shared half of the schema.
        $this->entityManager->getConnection()->executeStatement('DELETE FROM feed');
        $this->entityManager->clear();
    }

    /**
     * @param list<int|string> $parameters
     */
    private function scalarInt(string $sql, array $parameters = []): int
    {
        return self::asInt($this->entityManager->getConnection()->fetchOne($sql, $parameters));
    }

    private static function asInt(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
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

    public function testRoundTripReproducesTheAccountFieldForField(): void
    {
        $user = $this->seededUser('roundtrip@example.com');
        $userId = $user->requireId();
        $gzip = $this->backupOf($user);
        $before = $this->subscriptionShapes($userId);

        $result = $this->restorer()->start($this->reload($user), $gzip, 'REPLACE');

        self::assertSame(2, $result->tags);
        self::assertSame(2, $result->savedSearches);
        // Both feeds already exist as shared rows, so a same-instance restore
        // creates neither.
        self::assertSame(0, $result->feeds);
        self::assertSame(2, $result->subscriptions);

        $restored = $this->reload($user);
        self::assertSame('de', $restored->getLocale());
        self::assertTrue($restored->getPreferences()->isScrapeFallbackEnabled());

        $tags = $this->entityManager->getRepository(Tag::class)->findBy(['user' => $userId], ['name' => 'ASC']);
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
    }

    /**
     * `slug` is NOT_BACKED_UP because ids change, so the restore must regenerate it; a null slug silently breaks every
     * sidebar link and reader route to the search.
     */
    public function testRestoreRegeneratesTheSavedSearchSlug(): void
    {
        $user = $this->seededUser('slug-restore@example.com');
        $userId = $user->requireId();
        $gzip = $this->backupOf($user);

        $this->restorer()->start($this->reload($user), $gzip, 'REPLACE');

        $this->entityManager->clear();
        $restored = $this->entityManager->getRepository(SavedSearch::class)
            ->findOneBy(['user' => $userId, 'term' => 'rust lang']);
        self::assertInstanceOf(SavedSearch::class, $restored);

        $slugger = self::getContainer()->get(SavedSearchSlug::class);
        self::assertInstanceOf(SavedSearchSlug::class, $slugger);
        self::assertSame(
            $slugger->build($restored->requireId(), $restored->getTerm()),
            $restored->getSlug(),
        );
    }

    /**
     * The read half of BackupSchemaCoverageTest's write proof, off the same BackupFieldDeclarations::BACKED_UP list.
     * Restores onto a fresh account: the source's own Feed row would only be referenced, never written from the file.
     */
    public function testEveryBackedUpFieldSurvivesTheRestoreRoundTrip(): void
    {
        $source = $this->fullyPopulatedAccount()->create('drift-source@example.com');
        $foundation = $this->backupOf($source);
        $entryParts = $this->entryPartsOf($source);
        $sourceRows = $this->fixtureRowsOf($source);

        $target = $this->userFactory->create('drift-target@example.com');
        $this->deleteEveryFeed();

        $this->restorer()->start($this->reload($target), $foundation, 'REPLACE');
        foreach ($entryParts as $entryPart) {
            $this->entryPartRestorer()->load($this->reload($target), $entryPart);
        }
        $targetRows = $this->fixtureRowsOf($this->reload($target));

        $this->assertFieldsRoundTripped(User::class, $sourceRows['user'], $targetRows['user']);
        $this->assertFieldsRoundTripped(Preferences::class, $sourceRows['preferences'], $targetRows['preferences']);
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

        $this->assertFieldsRoundTripped(
            Entry::class,
            $sourceRows['entry'],
            $targetRows['entry'],
            [
                'feed', 'mediaSet.media', 'mediaSet.attachments', 'location.url',
                'discussion.url', 'discussion.commentsFeedUrl', 'discussion.commentsLoad',
                'discussion.bodyIsOpeningPost',
            ],
        );
        self::assertSame($sourceRows['feed']->getUrl(), $targetRows['entry']->getFeed()->getUrl());
        self::assertEquals($sourceRows['entry']->getMedia(), $targetRows['entry']->getMedia());
        self::assertEquals($sourceRows['entry']->getAttachments(), $targetRows['entry']->getAttachments());
        self::assertSame($sourceRows['entry']->getUrl(), $targetRows['entry']->getUrl());
        self::assertEquals($sourceRows['entry']->getDiscussion(), $targetRows['entry']->getDiscussion());

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
     * @return array{user: User, preferences: Preferences,
     *     tag: Tag, savedSearch: SavedSearch, feed: Feed, subscription: Subscription,
     *     subscriptionTag: SubscriptionTag, entry: Entry, entryState: EntryState}
     */
    private function fixtureRowsOf(User $user): array
    {
        $userId = $user->requireId();

        $subscription = $this->entityManager->getRepository(Subscription::class)->findOneBy(['user' => $userId]);
        self::assertInstanceOf(Subscription::class, $subscription);
        $subscriptionTags = $subscription->getSubscriptionTags();
        self::assertCount(1, $subscriptionTags);
        $subscriptionTag = reset($subscriptionTags);
        self::assertInstanceOf(SubscriptionTag::class, $subscriptionTag);

        $feed = $subscription->getFeed();
        // Load it now: the round-trip test deletes every feed row before it reads this one.
        $this->entityManager->initializeObject($feed);

        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['feed' => $feed]);
        self::assertInstanceOf(Entry::class, $entry);
        $entryState = $this->entityManager->getRepository(EntryState::class)->findOneBy(['user' => $userId]);
        self::assertInstanceOf(EntryState::class, $entryState);
        $tag = $this->entityManager->getRepository(Tag::class)->findOneBy(['user' => $userId]);
        self::assertInstanceOf(Tag::class, $tag);
        $savedSearch = $this->entityManager->getRepository(SavedSearch::class)->findOneBy(['user' => $userId]);
        self::assertInstanceOf(SavedSearch::class, $savedSearch);

        return [
            'user' => $user,
            'preferences' => $user->getPreferences(),
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
     * Compares every BACKED_UP field of $entityClass through its getter, before and after the restore, so a new scalar
     * field needs only a getter. $handledSeparately names the associations the caller compares itself.
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

    /**
     * Derives the getter from the field's path (`image.url` -> getImageUrl()), trying the bare name first so `isHidden`
     * finds isHidden(), not isIsHidden().
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

    public function testRestoreOntoAnEmptyInstanceRecreatesFeeds(): void
    {
        $user = $this->seededUser('empty-instance@example.com');
        $userId = $user->requireId();
        $gzip = $this->backupOf($user);
        $before = $this->subscriptionShapes($userId);
        $this->deleteEveryFeed();

        $result = $this->restorer()->start($this->reload($user), $gzip, 'REPLACE');

        self::assertSame(2, $result->feeds);
        self::assertSame(2, $result->subscriptions);
        self::assertSame(2, $result->tags);
        self::assertSame(2, $result->savedSearches);

        $this->entityManager->clear();
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

        self::assertSame($before, $this->subscriptionShapes($userId));
    }

    public function testAFeedRowAnotherUserReadsIsNotModified(): void
    {
        $user = $this->seededUser('shared-feed@example.com');
        $gzip = $this->backupOf($user);
        $feedId = $this->scalarInt('SELECT id FROM feed WHERE url = ?', [self::ONE_URL]);
        $this->entityManager->clear();

        $stranger = $this->userFactory->create('feed-stranger@example.com');
        $feed = $this->entityManager->find(Feed::class, $feedId);
        self::assertInstanceOf(Feed::class, $feed);
        $feed->setTitle('Theirs');
        $this->entityManager->persist(
            new Subscription($stranger, $feed, new \DateTimeImmutable('2026-07-03 10:00:00')),
        );
        $this->entityManager->flush();

        $this->restorer()->start($this->reload($user), $gzip, 'REPLACE');

        $this->entityManager->clear();
        $after = $this->entityManager->find(Feed::class, $feedId);
        self::assertInstanceOf(Feed::class, $after);
        self::assertSame('Theirs', $after->getTitle());
    }

    public function testOneLookupReferencesTheKnownFeedAndCreatesTheMissingOne(): void
    {
        $user = $this->seededUser('mixed-feeds@example.com');
        $userId = $user->requireId();
        $gzip = $this->backupOf($user);
        $before = $this->subscriptionShapes($userId);
        $this->entityManager->getConnection()->executeStatement('DELETE FROM feed WHERE url = ?', [self::TWO_URL]);
        $this->entityManager->clear();

        $result = $this->restorer()->start($this->reload($user), $gzip, 'REPLACE');

        self::assertSame(1, $result->feeds);
        self::assertSame(2, $result->subscriptions);
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM feed'));
        self::assertSame($before, $this->subscriptionShapes($userId));
        $this->entityManager->clear();
        $one = $this->entityManager->getRepository(Feed::class)->findOneBy(['url' => self::ONE_URL]);
        self::assertInstanceOf(Feed::class, $one);
        self::assertSame('W/"seeded-etag"', $one->getEtag());
    }

    public function testRefusalHappensBeforeAnyDeletion(): void
    {
        $source = $this->seededUser('fit-source@example.com');
        $gzip = $this->backupOf($source);

        $target = $this->userFactory->create('fit-target@example.com', maxSubscriptions: 1);
        $this->seedRichAccountForCappedTarget($target);
        $targetId = $target->requireId();

        try {
            $this->restorer()->start($this->reload($target), $gzip, 'REPLACE');
            self::fail('The restore accepted a backup that does not fit the account.');
        } catch (BackupDoesNotFitException) {
            // Expected — and nothing may have been deleted by now.
        }

        $this->entityManager->clear();
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM tag WHERE user_id = ?', [$targetId]));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM subscription WHERE user_id = ?', [$targetId]));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM entry_state WHERE user_id = ?', [$targetId]));
    }

    public function testWithoutTheConfirmationNothingHappens(): void
    {
        $user = $this->seededUser('unconfirmed@example.com');
        $userId = $user->requireId();
        $gzip = $this->backupOf($user);

        try {
            $this->restorer()->start($this->reload($user), $gzip, null);
            self::fail('The restore ran without the REPLACE confirmation.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('confirm', $exception->errors);
        }

        $this->entityManager->clear();
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM tag WHERE user_id = ?', [$userId]));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM subscription WHERE user_id = ?', [$userId]));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM entry_state WHERE user_id = ?', [$userId]));
    }

    public function testStartRefusesAnEntryPartBeforeDeletingAnything(): void
    {
        $user = $this->accountWithOneSubscription();
        try {
            $this->restorer()->start($user, $this->entryPartGzip(), 'REPLACE');
            self::fail('An entry part started a restore.');
        } catch (InvalidBackupException) {
        }
        self::assertSame(1, $this->subscriptionCount($user));
    }

    public function testStartLoadsTheFoundationAndReportsNoEntries(): void
    {
        $result = $this->restorer()->start($this->emptyAccount(), $this->foundationGzip(), 'REPLACE');

        self::assertSame(1, $result->tags);
        self::assertSame(1, $result->savedSearches);
        self::assertSame(1, $result->subscriptions);
        self::assertSame(0, $result->entries);
    }

    public function testTheFitCheckJudgesTheFoundationsClaimedTotals(): void
    {
        $foundation = $this->foundationGzip(totals: ['entries' => 500_001, 'entryStates' => 0]);
        $this->expectException(BackupDoesNotFitException::class);
        $this->restorer()->start($this->emptyAccount(), $foundation, 'REPLACE');
    }

    public function testARestoreCanBeRerunAfterItself(): void
    {
        $user = $this->seededUser('rerun@example.com');
        $userId = $user->requireId();
        $gzip = $this->backupOf($user);
        $before = $this->subscriptionShapes($userId);
        $this->deleteEveryFeed();

        $this->restorer()->start($this->reload($user), $gzip, 'REPLACE');
        $second = $this->restorer()->start($this->reload($user), $gzip, 'REPLACE');

        // The second run finds every shared row already in place, so it
        // re-creates only what the wipe removed.
        self::assertSame(0, $second->feeds);
        self::assertSame(2, $second->tags);
        self::assertSame(2, $second->savedSearches);
        self::assertSame(2, $second->subscriptions);

        $this->entityManager->clear();
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM feed'));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM tag WHERE user_id = ?', [$userId]));
        self::assertSame($before, $this->subscriptionShapes($userId));
        self::assertSame('de', $this->reload($user)->getLocale());
    }

    /**
     * One feed, every entry favourited, wider than RestoreEntryLoader's 500-row insert batch and 500-row state flush,
     * so every batch's read-back ids and every state must survive.
     */
    private function seedFeedWiderThanOneBatch(User $user, int $entryCount): void
    {
        $feed = $this->makeFeed('https://wide.example/feed.xml', 'Wide', 'xml');
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01 00:00:00')));
        for ($index = 0; $index < $entryCount; ++$index) {
            $entry = $this->makeEntry($feed, 'wide-guid-' . $index, 'Wide ' . $index, '2026-08-02');
            $state = new EntryState($user, $entry);
            $state->markFavorite();
            $this->entityManager->persist($state);
        }
        $this->entityManager->flush();
    }

    public function testAnEntryPartWiderThanOneInsertBatchLoadsEveryBatchAndItsStates(): void
    {
        $user = $this->userFactory->create('wide-batch@example.com');
        $this->seedFeedWiderThanOneBatch($user, 502);
        $userId = $user->requireId();
        $foundation = $this->backupOf($user);
        $entryParts = $this->entryPartsOf($user);
        $this->deleteEveryFeed();

        $this->restorer()->start($this->reload($user), $foundation, 'REPLACE');
        $entriesCreated = 0;
        $entryStatesCreated = 0;
        foreach ($entryParts as $entryPart) {
            $result = $this->entryPartRestorer()->load($this->reload($user), $entryPart);
            $entriesCreated += $result->entries;
            $entryStatesCreated += $result->entryStates;
        }

        self::assertSame(502, $entriesCreated);
        self::assertSame(502, $entryStatesCreated);
        $this->entityManager->clear();
        self::assertSame(502, $this->scalarInt('SELECT COUNT(*) FROM entry'));
        self::assertSame(502, $this->scalarInt('SELECT COUNT(*) FROM entry_state WHERE user_id = ?', [$userId]));
    }

    // Pass 1 refuses every content-driven flush failure, so RestoreLoadPassTest proves the flush wrap directly.

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

    /** The headline property: a file that cannot be fully accepted costs the account nothing. */
    public function testAReferentialRefusalLeavesEveryRowInPlace(): void
    {
        $user = $this->seededUser('dangling@example.com');
        $userId = $user->requireId();
        $gzip = $this->withoutTheFirstFeedLine($this->backupOf($user));

        try {
            $this->restorer()->start($this->reload($user), $gzip, 'REPLACE');
            self::fail('The restore accepted a subscription whose feed the file never declares.');
        } catch (InvalidBackupException) {
            // Expected — and nothing may have been deleted by now.
        }

        $this->assertTheSeededAccountSurvived($userId);
    }

    private function assertTheSeededAccountSurvived(int $userId): void
    {
        $this->entityManager->clear();
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

    /** One tag, one subscription and one entry state, so the refusal has something to protect. */
    private function seedRichAccountForCappedTarget(User $user): void
    {
        $feed = new Feed('https://capped.example/feed.xml');
        $this->entityManager->persist($feed);
        $tag = new Tag($user, 'Kept');
        $this->entityManager->persist($tag);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-04 08:00:00'));
        $subscription->addTag($tag, 0);
        $this->entityManager->persist($subscription);
        $entry = $this->makeEntry($feed, 'guid-capped', 'Capped', '2026-08-07');
        $this->entityManager->persist(new EntryState($user, $entry));
        $this->entityManager->flush();
    }
}
