<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Backup\BackupFitCheck;
use App\Service\Backup\BackupInventory;
use App\Service\Backup\Dto\BackupHeader;
use App\Service\Backup\Exception\BackupDoesNotFitException;
use App\Service\Backup\RestorePreviewer;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RestorePreviewerTest extends DbTestCase
{
    private UserFactory $users;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->users = new UserFactory($this->em, $hasher);
    }

    /** @param list<array<string, mixed>> $lines */
    private static function gzipOf(array $lines): string
    {
        $ndjson = implode("\n", array_map(
            static fn (array $line): string => json_encode($line, \JSON_THROW_ON_ERROR),
            $lines,
        )) . "\n";

        return (string) gzencode($ndjson);
    }

    /** @return array<string, mixed> */
    private static function header(): array
    {
        return [
            'kind' => 'header',
            'schemaVersion' => 2,
            'createdAt' => '2026-08-17T09:00:00+00:00',
            'sourceUrl' => 'https://source.example',
            'sourceEmail' => 'source@example.com',
        ];
    }

    /** @return array<string, mixed> */
    private static function account(): array
    {
        return [
            'kind' => 'account',
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'recommendationSettings' => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function feed(string $url): array
    {
        return ['kind' => 'feed', 'url' => $url, 'siteUrl' => null, 'title' => null,
            'description' => null, 'faviconUrl' => null, 'sourceFormat' => 'xml'];
    }

    /** @return array<string, mixed> */
    private static function entry(string $feedUrl, string $guidHash): array
    {
        return ['kind' => 'entry', 'feedUrl' => $feedUrl, 'guid' => 'guid-' . $guidHash,
            'guidHash' => $guidHash, 'url' => null, 'title' => 'One', 'author' => null,
            'summary' => null, 'contentHtml' => null, 'imageUrl' => null, 'imageWidth' => null,
            'imageHeight' => null, 'publishedAt' => null,
            'createdAt' => '2026-08-01T00:00:00+00:00', 'effectiveDate' => '2026-08-01T00:00:00+00:00'];
    }

    /** @return array<string, mixed> */
    private static function entryState(string $feedUrl, string $guidHash): array
    {
        return ['kind' => 'entryState', 'feedUrl' => $feedUrl, 'guidHash' => $guidHash,
            'isHidden' => true, 'isFavorite' => false, 'isKept' => false, 'hiddenAt' => null,
            'isViewed' => false, 'viewedAt' => null];
    }

    /** @return array<string, mixed> */
    private static function subscription(string $feedUrl): array
    {
        return ['kind' => 'subscription', 'feedUrl' => $feedUrl, 'customTitle' => null,
            'position' => 0, 'markedReadUntil' => null, 'createdAt' => '2026-07-01T00:00:00+00:00',
            'tags' => []];
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, mixed>
     */
    private static function footer(array $counts = []): array
    {
        return ['kind' => 'footer', 'counts' => $counts + [
            'tag' => 0, 'feed' => 0, 'subscription' => 0, 'entry' => 0, 'entryState' => 0,
        ]];
    }

    private static function someHeader(): BackupHeader
    {
        return new BackupHeader(
            schemaVersion: 2,
            createdAt: new \DateTimeImmutable('2026-08-17T09:00:00+00:00'),
            sourceUrl: null,
            sourceEmail: null,
        );
    }

    private function fitCheck(): BackupFitCheck
    {
        /** @var BackupFitCheck $fitCheck */
        $fitCheck = self::getContainer()->get(BackupFitCheck::class);

        return $fitCheck;
    }

    private function previewer(): RestorePreviewer
    {
        /** @var RestorePreviewer $previewer */
        $previewer = self::getContainer()->get(RestorePreviewer::class);

        return $previewer;
    }

    private function makeUser(string $email, ?int $maxSubscriptions = null): User
    {
        return $this->users->create($email, maxSubscriptions: $maxSubscriptions);
    }

    /**
     * A hand-built inventory rather than a generated file: the ceilings sit in
     * the millions of lines, and the point under test is the guard, not the
     * reader's ability to count that far.
     *
     * @param array<string, int> $counts
     */
    private static function inventoryOf(array $counts): BackupInventory
    {
        return new BackupInventory(
            header: self::someHeader(),
            tags: $counts['tags'] ?? 0,
            feeds: $counts['feeds'] ?? 1,
            subscriptions: $counts['subscriptions'] ?? 1,
            entries: $counts['entries'] ?? 0,
            entryStates: $counts['entryStates'] ?? 0,
        );
    }

    /**
     * Every counted dimension is bounded, not only the two that dominate the
     * load's runtime: tags, feeds and subscriptions all accumulate as managed
     * entities until the entry phase flushes, so an unbounded one would run a
     * WIPED account out of memory — and a fatal cannot be reported at all.
     *
     * @return iterable<string, array{array<string, int>}>
     */
    public static function inventoriesAboveACeiling(): iterable
    {
        yield 'tags' => [['tags' => 5_001]];
        yield 'feeds' => [['feeds' => 20_001]];
        yield 'entries' => [['entries' => 500_001]];
        yield 'entry states' => [['entryStates' => 500_001]];
    }

    /**
     * @param array<string, int> $counts
     */
    #[DataProvider('inventoriesAboveACeiling')]
    public function testRefusesAnyDimensionAboveItsSanityCeiling(array $counts): void
    {
        $email = str_replace(' ', '-', (string) array_key_first($counts)) . '-ceiling@example.com';

        $this->expectException(BackupDoesNotFitException::class);

        $this->fitCheck()->assertFits(self::inventoryOf($counts), $this->makeUser($email));
    }

    public function testAnInventoryOnEveryCeilingIsAccepted(): void
    {
        $onTheLine = self::inventoryOf([
            'tags' => 5_000, 'feeds' => 20_000, 'entries' => 500_000, 'entryStates' => 500_000,
        ]);

        $this->fitCheck()->assertFits($onTheLine, $this->makeUser('on-the-ceiling@example.com'));

        self::assertSame(500_000, $onTheLine->entries);
    }

    public function testPreviewRefusesMoreSubscriptionsThanTheAccountAllows(): void
    {
        $user = $this->makeUser('one-slot@example.com', maxSubscriptions: 1);
        $gzip = self::gzipOf([
            self::header(), self::account(),
            self::feed('https://a.example/feed.xml'), self::feed('https://b.example/feed.xml'),
            self::subscription('https://a.example/feed.xml'), self::subscription('https://b.example/feed.xml'),
            self::footer(['feed' => 2, 'subscription' => 2]),
        ]);

        $this->expectException(BackupDoesNotFitException::class);
        $this->expectExceptionMessageMatches('/allows 1/');

        $this->previewer()->preview($user, $gzip);
    }

    public function testPreviewEchoesTheInventoryAndTheCurrentAccountCounts(): void
    {
        $user = $this->makeUser('current-owner@example.com');
        $feed = new Feed('https://existing.example/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Tag($user, 'Existing'));
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01 00:00:00')));
        $this->em->persist(new RecommendationRun($user, new \DateTimeImmutable('2026-07-01 00:00:00')));
        $this->em->flush();

        $gzip = self::gzipOf([
            self::header(), self::account(),
            self::feed('https://a.example/feed.xml'),
            self::subscription('https://a.example/feed.xml'),
            self::footer(['feed' => 1, 'subscription' => 1]),
        ]);

        $preview = $this->previewer()->preview($user, $gzip);

        self::assertSame('source@example.com', $preview->header->sourceEmail);
        self::assertSame(1, $preview->toLoad->feeds);
        self::assertSame(1, $preview->toLoad->subscriptions);
        self::assertSame(1, $preview->currentSubscriptions);
        self::assertSame(1, $preview->currentTags);
        self::assertSame(0, $preview->currentEntryStates);
        self::assertSame(1, $preview->currentRecommendationRuns);
    }

    /**
     * The four numbers the user reads immediately before an irreversible wipe.
     * Every other case here leaves entries and states at zero, which a broken
     * count would satisfy just as well — COUNT(s.entry) over EntryState's
     * composite key in particular has no scalar id to fall back on.
     */
    public function testTheEntryAndStateCountsAreReportedNonZero(): void
    {
        $user = $this->makeUser('non-zero-counts@example.com');
        $feed = new Feed('https://counted.example/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01 00:00:00')));
        $when = new \DateTimeImmutable('2026-08-01 00:00:00');
        foreach (['counted-a', 'counted-b'] as $guid) {
            $entry = new Entry($feed, $guid, 'https://counted.example/' . $guid, $guid, $when, $when);
            $this->em->persist($entry);
            $this->em->persist(new EntryState($user, $entry));
        }
        $this->em->flush();

        $gzip = self::gzipOf([
            self::header(), self::account(),
            self::feed('https://a.example/feed.xml'),
            self::subscription('https://a.example/feed.xml'),
            self::entry('https://a.example/feed.xml', 'hash-a'),
            self::entry('https://a.example/feed.xml', 'hash-b'),
            self::entry('https://a.example/feed.xml', 'hash-c'),
            self::entryState('https://a.example/feed.xml', 'hash-a'),
            self::entryState('https://a.example/feed.xml', 'hash-b'),
            self::footer(['feed' => 1, 'subscription' => 1, 'entry' => 3, 'entryState' => 2]),
        ]);

        $preview = $this->previewer()->preview($user, $gzip);

        self::assertSame(3, $preview->toLoad->entries);
        self::assertSame(2, $preview->toLoad->entryStates);
        self::assertSame(2, $preview->currentEntryStates);
    }
}
