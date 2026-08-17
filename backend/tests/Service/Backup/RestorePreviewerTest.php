<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\Feed;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Backup\BackupFitCheck;
use App\Service\Backup\BackupInventory;
use App\Service\Backup\Dto\AccountLine;
use App\Service\Backup\Dto\BackupHeader;
use App\Service\Backup\Exception\BackupDoesNotFitException;
use App\Service\Backup\RestorePreviewer;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
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
            'schemaVersion' => 1,
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
            schemaVersion: 1,
            createdAt: new \DateTimeImmutable('2026-08-17T09:00:00+00:00'),
            sourceUrl: null,
            sourceEmail: null,
        );
    }

    private static function someAccount(): AccountLine
    {
        return new AccountLine(locale: 'en', scrapeFallbackEnabled: false, recommendationSettings: null);
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

    public function testRefusesMoreEntriesThanTheSanityCeiling(): void
    {
        $inventory = new BackupInventory(
            header: self::someHeader(),
            account: self::someAccount(),
            tags: 0,
            feeds: 1,
            subscriptions: 1,
            entries: 500_001,
            entryStates: 0,
        );

        $this->expectException(BackupDoesNotFitException::class);

        $this->fitCheck()->assertFits($inventory, $this->makeUser('fit-ceiling@example.com'));
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
}
