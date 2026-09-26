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
use App\Tests\Support\UserFactory;
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
    private const string FOUNDATION_FEED_URL = 'https://foundation.example/feed.xml';

    private UserFactory $users;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->users = new UserFactory($this->em, $hasher);
    }

    /**
     * The foundation part of a real export — the only part `start()` reads.
     * Built through the real exporter rather than hand-written NDJSON, so a
     * change that breaks the pair breaks this test rather than a fixture that
     * agrees with neither half.
     */
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
        $user = $this->users->create('one-subscription@example.com');
        $feed = new Feed('https://kept.example/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01 00:00:00')));
        $this->em->flush();

        return $user;
    }

    private function emptyAccount(): User
    {
        return $this->users->create('empty-account@example.com');
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

        return new FullyPopulatedAccount($this->em, $hasher);
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
        $feed->setEtag('W/"seeded-etag"');
        $feed->recordSuccessfulFetch(new \DateTimeImmutable('2026-08-10 07:00:00'), 60);
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
        $entry->getImage()->storePending('https://example.test/' . $guid . '.png', 640, 480);
        $entry->setPublishedAt(new \DateTimeImmutable($day . ' 04:00:00'));
        $this->em->persist($entry);

        return $entry;
    }

    /**
     * Two feeds, two tags with colours and per-subscription tag positions, two
     * saved searches (one whole-word), two subscriptions with a custom title
     * and a watermark, three entries with bodies and images, two entry
     * states, preferences on and a non-default locale.
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

        $this->em->flush();
        self::assertNotNull($entryB->getId());
    }

    private function seededUser(string $email): User
    {
        $user = $this->users->create($email);
        $this->seedRichAccount($user);

        return $user;
    }

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

    public function testRoundTripReproducesTheAccountFieldForField(): void
    {
        $user = $this->seededUser('roundtrip@example.com');
        $userId = $user->requireId();
        $gzip = $this->backupOf($user);
        $before = $this->subscriptionShapes($userId);

        $result = $this->restorer()->start($this->reloadUser($userId), $gzip, 'REPLACE');

        self::assertSame(2, $result->tags);
        self::assertSame(2, $result->savedSearches);
        // Both feeds already exist as shared rows, so a same-instance restore
        // creates neither.
        self::assertSame(0, $result->feeds);
        self::assertSame(2, $result->subscriptions);

        $restored = $this->reloadUser($userId);
        self::assertSame('de', $restored->getLocale());
        self::assertTrue($restored->getPreferences()->isScrapeFallbackEnabled());

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
    }

    /**
     * `slug` is deliberately NOT_BACKED_UP (restore reassigns ids, so a
     * carried-over slug would be stale) — but that makes it the restore
     * path's own job to set it. A restored search with a null slug breaks
     * every saved-search sidebar link and reader route, silently, so this
     * proves the regeneration directly rather than trusting the schema
     * declaration to imply it (#1118).
     */
    public function testRestoreRegeneratesTheSavedSearchSlug(): void
    {
        $user = $this->seededUser('slug-restore@example.com');
        $userId = $user->requireId();
        $gzip = $this->backupOf($user);

        $this->restorer()->start($this->reloadUser($userId), $gzip, 'REPLACE');

        $this->em->clear();
        $restored = $this->em->getRepository(SavedSearch::class)
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
        $foundation = $this->backupOf($source);
        $entryParts = $this->entryPartsOf($source);
        $sourceRows = $this->fixtureRowsOf($source);

        $target = $this->users->create('drift-target@example.com');
        $targetId = $target->requireId();
        $this->deleteEveryFeed();

        $this->restorer()->start($this->reloadUser($targetId), $foundation, 'REPLACE');
        foreach ($entryParts as $entryPart) {
            $this->entryPartRestorer()->load($this->reloadUser($targetId), $entryPart);
        }
        $targetRows = $this->fixtureRowsOf($this->reloadUser($targetId));

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

    public function testRestoreOntoAnEmptyInstanceRecreatesFeeds(): void
    {
        $user = $this->seededUser('empty-instance@example.com');
        $userId = $user->requireId();
        $gzip = $this->backupOf($user);
        $before = $this->subscriptionShapes($userId);
        $this->deleteEveryFeed();

        $result = $this->restorer()->start($this->reloadUser($userId), $gzip, 'REPLACE');

        self::assertSame(2, $result->feeds);
        self::assertSame(2, $result->subscriptions);
        self::assertSame(2, $result->tags);
        self::assertSame(2, $result->savedSearches);

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

        self::assertSame($before, $this->subscriptionShapes($userId));
    }

    public function testAFeedRowAnotherUserReadsIsNotModified(): void
    {
        $user = $this->seededUser('shared-feed@example.com');
        $userId = $user->requireId();
        $gzip = $this->backupOf($user);
        $feedId = $this->scalarInt('SELECT id FROM feed WHERE url = ?', [self::ONE_URL]);
        $this->em->clear();

        $stranger = $this->users->create('feed-stranger@example.com');
        $feed = $this->em->find(Feed::class, $feedId);
        self::assertInstanceOf(Feed::class, $feed);
        $feed->setTitle('Theirs');
        $this->em->persist(new Subscription($stranger, $feed, new \DateTimeImmutable('2026-07-03 10:00:00')));
        $this->em->flush();

        $this->restorer()->start($this->reloadUser($userId), $gzip, 'REPLACE');

        $this->em->clear();
        $after = $this->em->find(Feed::class, $feedId);
        self::assertInstanceOf(Feed::class, $after);
        self::assertSame('Theirs', $after->getTitle());
    }

    public function testOneLookupReferencesTheKnownFeedAndCreatesTheMissingOne(): void
    {
        $user = $this->seededUser('mixed-feeds@example.com');
        $userId = $user->requireId();
        $gzip = $this->backupOf($user);
        $before = $this->subscriptionShapes($userId);
        $this->em->getConnection()->executeStatement('DELETE FROM feed WHERE url = ?', [self::TWO_URL]);
        $this->em->clear();

        $result = $this->restorer()->start($this->reloadUser($userId), $gzip, 'REPLACE');

        self::assertSame(1, $result->feeds);
        self::assertSame(2, $result->subscriptions);
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM feed'));
        self::assertSame($before, $this->subscriptionShapes($userId));
        $this->em->clear();
        $one = $this->em->getRepository(Feed::class)->findOneBy(['url' => self::ONE_URL]);
        self::assertInstanceOf(Feed::class, $one);
        self::assertSame('W/"seeded-etag"', $one->getEtag());
    }

    public function testRefusalHappensBeforeAnyDeletion(): void
    {
        $source = $this->seededUser('fit-source@example.com');
        $gzip = $this->backupOf($source);

        $target = $this->users->create('fit-target@example.com', maxSubscriptions: 1);
        $this->seedRichAccountForCappedTarget($target);
        $targetId = $target->requireId();

        try {
            $this->restorer()->start($this->reloadUser($targetId), $gzip, 'REPLACE');
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
        $userId = $user->requireId();
        $gzip = $this->backupOf($user);

        try {
            $this->restorer()->start($this->reloadUser($userId), $gzip, null);
            self::fail('The restore ran without the REPLACE confirmation.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('confirm', $e->errors);
        }

        $this->em->clear();
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

        $this->restorer()->start($this->reloadUser($userId), $gzip, 'REPLACE');
        $second = $this->restorer()->start($this->reloadUser($userId), $gzip, 'REPLACE');

        // The second run finds every shared row already in place, so it
        // re-creates only what the wipe removed.
        self::assertSame(0, $second->feeds);
        self::assertSame(2, $second->tags);
        self::assertSame(2, $second->savedSearches);
        self::assertSame(2, $second->subscriptions);

        $this->em->clear();
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM feed'));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM tag WHERE user_id = ?', [$userId]));
        self::assertSame($before, $this->subscriptionShapes($userId));
        self::assertSame('de', $this->reloadUser($userId)->getLocale());
    }

    /**
     * One feed with more entries than a single insert batch, every entry
     * favourited, so the entry part crosses both RestoreEntryLoader boundaries
     * at once: the 500-row insert batch (ids read back per batch) and the
     * 500-row held-state flush. Every batch's ids and every state must survive.
     */
    private function seedFeedWiderThanOneBatch(User $user, int $entryCount): void
    {
        $feed = $this->makeFeed('https://wide.example/feed.xml', 'Wide', 'xml');
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01 00:00:00')));
        for ($index = 0; $index < $entryCount; ++$index) {
            $entry = $this->makeEntry($feed, 'wide-guid-' . $index, 'Wide ' . $index, '2026-08-02');
            $state = new EntryState($user, $entry);
            $state->setIsFavorite(true);
            $this->em->persist($state);
        }
        $this->em->flush();
    }

    public function testAnEntryPartWiderThanOneInsertBatchLoadsEveryBatchAndItsStates(): void
    {
        $user = $this->users->create('wide-batch@example.com');
        $this->seedFeedWiderThanOneBatch($user, 502);
        $userId = $user->requireId();
        $foundation = $this->backupOf($user);
        $entryParts = $this->entryPartsOf($user);
        $this->deleteEveryFeed();

        $this->restorer()->start($this->reloadUser($userId), $foundation, 'REPLACE');
        $entriesCreated = 0;
        $entryStatesCreated = 0;
        foreach ($entryParts as $entryPart) {
            $result = $this->entryPartRestorer()->load($this->reloadUser($userId), $entryPart);
            $entriesCreated += $result->entries;
            $entryStatesCreated += $result->entryStates;
        }

        self::assertSame(502, $entriesCreated);
        self::assertSame(502, $entryStatesCreated);
        $this->em->clear();
        self::assertSame(502, $this->scalarInt('SELECT COUNT(*) FROM entry'));
        self::assertSame(502, $this->scalarInt('SELECT COUNT(*) FROM entry_state WHERE user_id = ?', [$userId]));
    }

    // No content can reach RestoreLoadPass's flush()-catch(DbalException) branch
    // any more (#412: pass 1 refuses duplicates), so the wrap is proven directly
    // in RestoreLoadPassTest::testADatabaseFailureDuringTheAccountShapeFlushIsAWrappedBackupError.

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
        $userId = $user->requireId();
        $gzip = $this->withoutTheFirstFeedLine($this->backupOf($user));

        try {
            $this->restorer()->start($this->reloadUser($userId), $gzip, 'REPLACE');
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
