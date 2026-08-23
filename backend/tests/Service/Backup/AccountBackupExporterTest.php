<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\RecommendationSettings;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Backup\AccountBackupExporter;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountBackupExporterTest extends DbTestCase
{
    private function makeUser(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email, locale: 'de');
    }

    private function exporter(): AccountBackupExporter
    {
        $exporter = self::getContainer()->get(AccountBackupExporter::class);
        self::assertInstanceOf(AccountBackupExporter::class, $exporter);

        return $exporter;
    }

    /** @return list<array<string, mixed>> */
    private function decodedLines(User $user): array
    {
        $lines = [];
        foreach ($this->exporter()->lines($user, 'https://source.example') as $line) {
            $decoded = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            /** @var array<string, mixed> $decoded */
            $lines[] = $decoded;
        }

        return $lines;
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

        $lines = $this->decodedLines($user);

        self::assertSame(
            ['header', 'account', 'tag', 'feed', 'subscription', 'entry', 'entryState', 'footer'],
            array_column($lines, 'kind'),
        );
        self::assertSame(1, $lines[0]['schemaVersion']);
        self::assertSame('export-order@example.com', $lines[0]['sourceEmail']);
        self::assertSame('https://source.example', $lines[0]['sourceUrl']);
        self::assertSame('de', $lines[1]['locale']);
        self::assertSame('Tech', $lines[2]['name']);
        self::assertSame(1, $lines[2]['position']);
        self::assertSame('https://one.example/feed.xml', $lines[3]['url']);
        self::assertArrayNotHasKey('etag', $lines[3]);
        self::assertArrayNotHasKey('status', $lines[3]);
        self::assertSame('My One', $lines[4]['customTitle']);
        self::assertSame(4, $lines[4]['position']);
        self::assertSame([['name' => 'Tech', 'position' => 3]], $lines[4]['tags']);
        self::assertSame('guid-1', $lines[5]['guid']);
        self::assertSame(hash('sha256', 'guid-1'), $lines[5]['guidHash']);
        self::assertSame('<p>body</p>', $lines[5]['contentHtml']);
        self::assertTrue($lines[6]['isFavorite']);
        self::assertTrue($lines[6]['isViewed']);
        self::assertSame(
            ['tag' => 1, 'feed' => 1, 'subscription' => 1, 'entry' => 1, 'entryState' => 1],
            $lines[7]['counts'],
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

        $lines = $this->decodedLines($user);

        /** @var list<string> $kindList */
        $kindList = array_column($lines, 'kind');
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

        $lines = $this->decodedLines($user);

        /** @var list<string> $kindList */
        $kindList = array_column($lines, 'kind');
        $kinds = array_count_values($kindList);
        self::assertSame(1, $kinds['feed']);
        self::assertSame(1, $kinds['subscription']);
        self::assertSame(1, $kinds['entry']);
        self::assertSame(1, $kinds['entryState']);

        $entryStateLines = array_values(
            array_filter($lines, static fn (array $line): bool => 'entryState' === $line['kind']),
        );
        self::assertSame(hash('sha256', 'kept-guid'), $entryStateLines[0]['guidHash']);

        $footer = $lines[\count($lines) - 1];
        /** @var array<string, int> $footerCounts */
        $footerCounts = $footer['counts'];
        self::assertSame(1, $footerCounts['entryState']);
    }

    public function testEntryReadingStaysBatchedNotBuffered(): void
    {
        $user = $this->makeUser('export-streams@example.com');
        $feed = new Feed('https://big.example/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        for ($i = 0; $i < 1201; ++$i) {
            $entry = new Entry(
                $feed,
                'guid-' . $i,
                null,
                'Entry ' . $i,
                new \DateTimeImmutable('2026-08-01T00:00:00Z'),
                new \DateTimeImmutable('2026-08-01T00:00:00Z'),
            );
            $this->em->persist($entry);
            if (0 === $i % 200) {
                $this->em->flush();
            }
        }
        $this->em->flush();
        $this->em->clear();
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        $entryLines = 0;
        foreach ($this->exporter()->lines($user, null) as $line) {
            if (!str_contains($line, '"kind":"entry"')) {
                continue;
            }
            ++$entryLines;
            // The identity map must never hold the whole corpus: a buffered
            // SELECT hydrates all 1201 entries before the first yield, which
            // is exactly the 349.6 MiB failure the spec measured.
            $identityMap = $this->em->getUnitOfWork()->getIdentityMap();
            $held = \count($identityMap[Entry::class] ?? []);
            self::assertLessThanOrEqual(500, $held, 'entry hydration is not batched');
        }
        self::assertSame(1201, $entryLines);
    }

    public function testExportsTheStoredPreferenceProfileOnTheAccountLine(): void
    {
        $user = $this->makeUser('profile-export@example.com');
        $settings = new RecommendationSettings($user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: 40,
            keptCap: 40,
            viewedCap: 80,
            candidatePoolSize: 1000,
            lookbackDays: 2,
            picksLimit: 50,
            contextWindow: null,
            batchCount: null,
            debugEnabled: false,
            profileText: 'Reads long-form essays about urban planning.',
        ));
        $this->em->persist($settings);
        $this->em->flush();

        $accountLine = $this->decodedLines($user)[1];

        self::assertIsArray($accountLine['recommendationSettings']);
        self::assertSame(
            'Reads long-form essays about urban planning.',
            $accountLine['recommendationSettings']['profileText'],
        );
    }
}
