<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\RecommendationSettings;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Backup\AccountBackupExporter;
use App\Service\Backup\BackupPart;
use App\Service\Backup\BackupReader;
use App\Service\Recommendation\RecommendationBatchSize;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Tests\DbTestCase;
use App\Tests\Support\FullyPopulatedAccount;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountBackupExporterTest extends DbTestCase
{
    private function hasher(): UserPasswordHasherInterface
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return $hasher;
    }

    private function makeUser(string $email): User
    {
        return (new UserFactory($this->em, $this->hasher()))->create($email, locale: 'de');
    }

    private function exporter(): AccountBackupExporter
    {
        $exporter = self::getContainer()->get(AccountBackupExporter::class);
        self::assertInstanceOf(AccountBackupExporter::class, $exporter);

        return $exporter;
    }

    /** @return list<list<array<string, mixed>>> decoded lines per part, in yield order */
    private function decodedParts(User $user, ?string $sourceUrl = 'https://source.example'): array
    {
        $parts = [];
        foreach ($this->exporter()->parts($user, $sourceUrl) as $part) {
            $parts[] = $this->decodedLinesOf($part);
        }

        return $parts;
    }

    /** @return list<array<string, mixed>> */
    private function decodedLinesOf(BackupPart $part): array
    {
        $lines = [];
        foreach (explode("\n", rtrim((string) gzdecode($part->gzipBytes), "\n")) as $line) {
            $decoded = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            /** @var array<string, mixed> $decoded */
            $lines[] = $decoded;
        }

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $part
     *
     * @return array<string, mixed>
     */
    private static function footerOf(array $part): array
    {
        $footer = end($part);

        return \is_array($footer) ? $footer : throw new \LogicException('The part has no footer line.');
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function entryIdentityKey(array $line): string
    {
        $feedUrl = $line['feedUrl'];
        $guidHash = $line['guidHash'];
        if (!\is_string($feedUrl) || !\is_string($guidHash)) {
            throw new \LogicException('feedUrl and guidHash must be strings.');
        }

        return $feedUrl . '|' . $guidHash;
    }

    public function testExportsEveryKindInFileOrderWithAClosingFooter(): void
    {
        $user = $this->makeUser('export-order@example.com');
        $feed = new Feed('https://one.example/feed.xml');
        $feed->setTitle('One');
        $feed->setSiteUrl('https://one.example');
        $this->em->persist($feed);
        $tag = new Tag($user, 'Tech');
        $tag->setColor('#a1b2c3');
        $tag->setPosition(1);
        $this->em->persist($tag);
        $savedSearch = new SavedSearch($user, 'climate policy', true);
        $savedSearch->setPosition(3);
        $this->em->persist($savedSearch);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $subscription->setCustomTitle('My One');
        $subscription->setPosition(4);
        $subscription->setMarkedReadUntil(new \DateTimeImmutable('2026-08-01T00:00:00Z'));
        $subscription->addTag($tag, 3);
        $this->em->persist($subscription);
        $entry = new Entry(
            $feed,
            'guid-1',
            'https://one.example/a',
            'Article',
            new \DateTimeImmutable('2026-08-02T00:00:00Z'),
            new \DateTimeImmutable('2026-08-02T00:00:00Z'),
        );
        $entry->setContentHtml('<p>body</p>');
        $this->em->persist($entry);
        $state = new EntryState($user, $entry);
        $state->setIsFavorite(true);
        $state->markViewed(new \DateTimeImmutable('2026-08-03T00:00:00Z'));
        $this->em->persist($state);
        $this->em->flush();

        $parts = $this->decodedParts($user);
        self::assertCount(2, $parts);
        [$entriesPart, $foundationPart] = $parts;

        self::assertSame(['header', 'entry', 'entryState', 'footer'], array_column($entriesPart, 'kind'));
        self::assertSame(
            ['header', 'account', 'tag', 'savedSearch', 'feed', 'subscription', 'footer'],
            array_column($foundationPart, 'kind'),
        );

        self::assertSame(3, $entriesPart[0]['schemaVersion']);
        self::assertSame('export-order@example.com', $entriesPart[0]['sourceEmail']);
        self::assertSame('https://source.example', $entriesPart[0]['sourceUrl']);
        self::assertSame('de', $foundationPart[1]['locale']);
        self::assertSame('Tech', $foundationPart[2]['name']);
        self::assertSame(1, $foundationPart[2]['position']);
        self::assertSame('savedSearch', $foundationPart[3]['kind']);
        self::assertSame('climate policy', $foundationPart[3]['term']);
        self::assertTrue($foundationPart[3]['wholeWord']);
        self::assertFalse($foundationPart[3]['phrase']);
        self::assertSame(3, $foundationPart[3]['position']);
        self::assertSame('https://one.example/feed.xml', $foundationPart[4]['url']);
        self::assertArrayNotHasKey('etag', $foundationPart[4]);
        self::assertArrayNotHasKey('status', $foundationPart[4]);
        self::assertSame('My One', $foundationPart[5]['customTitle']);
        self::assertSame(4, $foundationPart[5]['position']);
        self::assertSame([['name' => 'Tech', 'position' => 3]], $foundationPart[5]['tags']);
        self::assertSame('guid-1', $entriesPart[1]['guid']);
        self::assertSame(hash('sha256', 'guid-1'), $entriesPart[1]['guidHash']);
        self::assertSame('<p>body</p>', $entriesPart[1]['contentHtml']);
        self::assertTrue($entriesPart[2]['isFavorite']);
        self::assertTrue($entriesPart[2]['isViewed']);
        self::assertSame(
            ['tag' => 0, 'savedSearch' => 0, 'feed' => 0, 'subscription' => 0, 'entry' => 1, 'entryState' => 1],
            self::footerOf($entriesPart)['counts'],
        );
        self::assertSame(
            ['tag' => 1, 'savedSearch' => 1, 'feed' => 1, 'subscription' => 1, 'entry' => 0, 'entryState' => 0],
            self::footerOf($foundationPart)['counts'],
        );
    }

    public function testExportsOnlyTheGivenUsersRows(): void
    {
        $user = $this->makeUser('export-mine@example.com');
        $other = $this->makeUser('export-other@example.com');
        $feed = new Feed('https://shared.example/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->persist(new Subscription($other, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $otherTag = new Tag($other, 'Not yours');
        $this->em->persist($otherTag);
        $entry = new Entry(
            $feed,
            'g',
            null,
            'A',
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->persist(new EntryState($other, $entry));
        $this->em->flush();

        $allLines = array_merge(...$this->decodedParts($user));

        /** @var list<string> $kindList */
        $kindList = array_column($allLines, 'kind');
        $kinds = array_count_values($kindList);
        self::assertSame(1, $kinds['subscription']);
        self::assertSame(1, $kinds['entry']);
        self::assertArrayNotHasKey('tag', $kinds);
        self::assertArrayNotHasKey('entryState', $kinds);
    }

    public function testEntryStateForAnUnsubscribedFeedIsNotExported(): void
    {
        $user = $this->makeUser('export-orphan@example.com');

        $subscribedFeed = new Feed('https://subscribed.example/feed.xml');
        $this->em->persist($subscribedFeed);
        $this->em->persist(
            new Subscription($user, $subscribedFeed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $subscribedEntry = new Entry(
            $subscribedFeed,
            'kept-guid',
            null,
            'Kept',
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
        );
        $this->em->persist($subscribedEntry);
        $subscribedState = new EntryState($user, $subscribedEntry);
        $subscribedState->setIsFavorite(true);
        $this->em->persist($subscribedState);

        // No Subscription row exists for this feed — exactly the state
        // SubscriptionService::unsubscribe leaves behind, since it removes the
        // subscription without touching entry_state (see
        // EntryStateRepository::stateCountsForUser's own docblock).
        $orphanFeed = new Feed('https://unsubscribed.example/feed.xml');
        $this->em->persist($orphanFeed);
        $orphanEntry = new Entry(
            $orphanFeed,
            'orphan-guid',
            null,
            'Orphan',
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
        );
        $this->em->persist($orphanEntry);
        $orphanState = new EntryState($user, $orphanEntry);
        $orphanState->setIsFavorite(true);
        $this->em->persist($orphanState);

        $this->em->flush();

        $parts = $this->decodedParts($user);
        $allLines = array_merge(...$parts);

        /** @var list<string> $kindList */
        $kindList = array_column($allLines, 'kind');
        $kinds = array_count_values($kindList);
        self::assertSame(1, $kinds['feed']);
        self::assertSame(1, $kinds['subscription']);
        self::assertSame(1, $kinds['entry']);
        self::assertSame(1, $kinds['entryState']);

        $entryStateLines = array_values(
            array_filter($allLines, static fn (array $line): bool => 'entryState' === $line['kind']),
        );
        self::assertSame(hash('sha256', 'kept-guid'), $entryStateLines[0]['guidHash']);

        /** @var array<string, int> $footerCounts */
        $footerCounts = self::footerOf($parts[0])['counts'];
        self::assertSame(1, $footerCounts['entryState']);
    }

    public function testTheAccountLineCarriesNoRecommendationSettings(): void
    {
        $user = $this->makeUser('no-recommendation-settings@example.com');
        $settings = new RecommendationSettings($user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: 'Only long reads.',
            favoritesCap: 40,
            keptCap: 40,
            viewedCap: 80,
            candidatePoolSize: 1000,
            lookbackDays: 2,
            picksLimit: 50,
            contextWindow: null,
            batchSize: RecommendationBatchSize::Large,
            debugEnabled: false,
            profileText: 'Reads long-form essays about urban planning.',
        ));
        $this->em->persist($settings);
        $this->em->flush();

        [$foundationPart] = $this->decodedParts($user);
        $accountLine = $foundationPart[0];

        self::assertArrayNotHasKey('recommendationSettings', $accountLine);
    }

    public function testASmallAccountYieldsEntryPartsThenTheFoundationLast(): void
    {
        $user = (new FullyPopulatedAccount($this->em, $this->hasher()))->create('small-account@example.com');

        $memberNames = [];
        $headers = [];
        foreach ($this->exporter()->parts($user, 'https://source.example') as $part) {
            $memberNames[] = $part->memberName;
            $headers[] = $this->decodedLinesOf($part)[0];
        }

        self::assertSame(['001-entries.ndjson.gz', '000-foundation.ndjson.gz'], $memberNames);
        self::assertSame(1, $headers[0]['part']);
        self::assertNull($headers[0]['parts']);
        self::assertNull($headers[0]['totals']);
        self::assertSame(0, $headers[1]['part']);
        self::assertSame(2, $headers[1]['parts']);
        self::assertSame(['entries' => 1, 'entryStates' => 1], $headers[1]['totals']);
        self::assertSame($headers[0]['backupId'], $headers[1]['backupId']);
        self::assertSame($headers[0]['createdAt'], $headers[1]['createdAt']);
    }

    public function testEveryEntryStateTravelsInTheSamePartAsItsEntry(): void
    {
        $user = (new FullyPopulatedAccount($this->em, $this->hasher()))->create('states-travel@example.com');

        foreach ($this->decodedParts($user) as $part) {
            $entryKeys = [];
            foreach ($part as $line) {
                if ('entry' === $line['kind']) {
                    $entryKeys[self::entryIdentityKey($line)] = true;
                }
            }

            foreach ($part as $line) {
                if ('entryState' === $line['kind']) {
                    self::assertArrayHasKey(self::entryIdentityKey($line), $entryKeys);
                }
            }
        }
    }

    public function testTheEntryBudgetSplitsALargeFeedAcrossPartsWithBoundedEntryHydration(): void
    {
        $user = $this->makeUser('export-budget@example.com');
        $feed = new Feed('https://budget.example/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        for ($i = 0; $i < 2001; ++$i) {
            $entry = new Entry(
                $feed,
                'guid-' . $i,
                null,
                'Entry ' . $i,
                new \DateTimeImmutable('2026-08-01T00:00:00Z'),
                new \DateTimeImmutable('2026-08-01T00:00:00Z'),
            );
            $this->em->persist($entry);
            if (0 === $i % 500) {
                $this->em->flush();
            }
        }
        $this->em->flush();
        $this->em->clear();
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        $entryCountsPerPart = [];
        $headers = [];
        foreach ($this->exporter()->parts($user, null) as $part) {
            $identityMap = $this->em->getUnitOfWork()->getIdentityMap();
            $held = \count($identityMap[Entry::class] ?? []);
            self::assertLessThanOrEqual(500, $held, 'entry hydration is not batched');

            $lines = $this->decodedLinesOf($part);
            $headers[] = $lines[0];
            $entryCountsPerPart[] = \count(array_filter(
                $lines,
                static fn (array $line): bool => 'entry' === $line['kind'],
            ));
        }

        self::assertSame([2000, 1, 0], $entryCountsPerPart);
        self::assertSame(1, $headers[0]['part']);
        self::assertSame(2, $headers[1]['part']);
        self::assertSame(0, $headers[2]['part']);
        self::assertSame(3, $headers[2]['parts']);
        self::assertSame(['entries' => 2001, 'entryStates' => 0], $headers[2]['totals']);
    }

    public function testAnAccountWithNoEntriesYieldsOnlyTheFoundation(): void
    {
        $user = $this->makeUser('export-no-entries@example.com');
        $feed = new Feed('https://empty.example/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->flush();

        $parts = $this->decodedParts($user);

        self::assertCount(1, $parts);
        $header = $parts[0][0];
        self::assertSame(0, $header['part']);
        self::assertSame(1, $header['parts']);
        self::assertSame(['entries' => 0, 'entryStates' => 0], $header['totals']);
    }

    public function testTheFoundationStaysConsistentWithEntryPartsWhenAFeedUrlChangesMidExport(): void
    {
        $user = $this->makeUser('mid-export-rewrite@example.com');
        $feed = new Feed('https://original.example/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->persist(new Entry(
            $feed,
            'g-1',
            'https://original.example/a',
            'A',
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
        ));
        $this->em->flush();
        $feedId = (int) $feed->getId();

        $parts = [];
        $rewritten = false;
        foreach ($this->exporter()->parts($user, null) as $part) {
            $parts[] = $this->decodedLinesOf($part);
            if (!$rewritten) {
                $this->rewriteFeedUrl($feedId, 'https://rewritten.example/feed.xml');
                $rewritten = true;
            }
        }

        $entryFeedUrls = self::feedUrlsOfKind($parts, 'entry');
        self::assertNotEmpty($entryFeedUrls);
        foreach ($entryFeedUrls as $feedUrl) {
            self::assertContains($feedUrl, self::foundationFeedUrls($parts));
        }
    }

    private function rewriteFeedUrl(int $feedId, string $url): void
    {
        $feed = $this->em->find(Feed::class, $feedId);
        self::assertInstanceOf(Feed::class, $feed);
        $feed->setUrl($url);
        $this->em->flush();
    }

    /**
     * @param list<list<array<string, mixed>>> $parts
     *
     * @return list<string>
     */
    private static function feedUrlsOfKind(array $parts, string $kind): array
    {
        $urls = [];
        foreach ($parts as $part) {
            foreach ($part as $line) {
                if ($kind === $line['kind'] && \is_string($line['feedUrl'])) {
                    $urls[$line['feedUrl']] = true;
                }
            }
        }

        return array_keys($urls);
    }

    /**
     * @param list<list<array<string, mixed>>> $parts
     *
     * @return list<string>
     */
    private static function foundationFeedUrls(array $parts): array
    {
        $urls = [];
        foreach ($parts as $part) {
            foreach ($part as $line) {
                if ('feed' === $line['kind'] && \is_string($line['url'])) {
                    $urls[] = $line['url'];
                }
            }
        }

        return $urls;
    }

    public function testEveryYieldedPartIsAValidDocumentThatTheReaderAccepts(): void
    {
        $user = (new FullyPopulatedAccount($this->em, $this->hasher()))->create('reader-round-trip@example.com');

        foreach ($this->exporter()->parts($user, 'https://source.example') as $part) {
            $lineCount = 0;
            foreach ((new BackupReader())->read($part->gzipBytes) as $line) {
                ++$lineCount;
            }
            self::assertGreaterThan(0, $lineCount);
        }
    }
}
