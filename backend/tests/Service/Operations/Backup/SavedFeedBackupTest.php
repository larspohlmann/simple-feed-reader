<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\Entry;
use App\Entity\EntryOrigin;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\SavedEntryOrigin;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Repository\SavedEntryOriginRepository;
use App\Service\Backup\AccountBackupExporter;
use App\Service\Backup\AccountRestorer;
use App\Service\Backup\BackupInspector;
use App\Service\Backup\EntryPartRestorer;
use App\Tests\DbTestCase;
use App\Tests\Support\SavedPostFixtures;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SavedFeedBackupTest extends DbTestCase
{
    private UserFactory $userFactory;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->userFactory = new UserFactory($this->entityManager, $hasher);
    }

    public function testASavedFeedItsPostItsFavouriteAndItsOriginComeBackAsTheRestoringUsersOwn(): void
    {
        $exporter = $this->userFactory->create('saved-exporter@example.test');
        $restorer = $this->userFactory->create('saved-restorer@example.test');
        $this->savedPostWithOrigin($exporter);

        $this->restoreInto($restorer, $exporter);

        $feeds = $this->entityManager->getRepository(Feed::class);
        $restoredFeed = $feeds->findOneBy(['url' => sprintf('saved:%d', $restorer->requireId())]);
        self::assertNotNull($restoredFeed);
        self::assertSame(SourceFormat::SAVED, $restoredFeed->getSourceFormat());
        self::assertNull($feeds->findOneBy(['url' => sprintf('saved:%d', $exporter->requireId() + 100000)]));
        $copy = $this->entityManager->getRepository(Entry::class)->findOneBy(['feed' => $restoredFeed]);
        self::assertNotNull($copy);
        /** @var SavedEntryOriginRepository $origins */
        $origins = $this->entityManager->getRepository(SavedEntryOrigin::class);
        self::assertEquals(
            [$copy->requireId() => new EntryOrigin('Heise', 'https://heise.example/favicon.ico')],
            $origins->originsByEntryIds([$copy->requireId()]),
        );
        $state = $this->entityManager->getRepository(EntryState::class)
            ->findOneBy(['user' => $restorer->requireId(), 'entry' => $copy]);
        self::assertNotNull($state);
        self::assertTrue($state->isFavorite());
    }

    public function testAFileNamingAnotherUsersSavedFeedLoadsIntoTheRestoringUsersOwn(): void
    {
        $exporter = $this->userFactory->create('saved-source@example.test');
        $bystander = $this->userFactory->create('saved-bystander@example.test');
        $restorer = $this->userFactory->create('saved-target@example.test');
        $this->savedPostWithOrigin($exporter);
        $bystanderFeed = $this->savedFeedOf($bystander);

        $this->restoreInto($restorer, $exporter, sprintf('saved:%d', $bystander->requireId()));

        $this->entityManager->clear();
        $entries = $this->entityManager->getRepository(Entry::class);
        self::assertCount(0, $entries->findBy(['feed' => $bystanderFeed->requireId()]));
        $restoredFeed = $this->entityManager->getRepository(Feed::class)
            ->findOneBy(['url' => sprintf('saved:%d', $restorer->requireId())]);
        self::assertNotNull($restoredFeed);
        self::assertCount(1, $entries->findBy(['feed' => $restoredFeed]));
    }

    public function testASavedFeedIsNotCountedAsASubscriptionTheFitCheckJudges(): void
    {
        $gzip = $this->gzipOf([
            $this->header(),
            ['kind' => 'account', 'locale' => 'en', 'scrapeFallbackEnabled' => false, 'magazineStyle' => 'boxed'],
            ['kind' => 'feed', 'url' => 'https://a.example/feed.xml', 'sourceFormat' => 'xml'] + $this->feedFields(),
            ['kind' => 'feed', 'url' => 'https://b.example/feed.xml', 'sourceFormat' => 'xml'] + $this->feedFields(),
            ['kind' => 'feed', 'url' => 'saved:4', 'sourceFormat' => 'saved'] + $this->feedFields(),
            $this->subscriptionLine('https://a.example/feed.xml'),
            $this->subscriptionLine('https://b.example/feed.xml'),
            $this->subscriptionLine('saved:4'),
            ['kind' => 'footer', 'counts' => ['feed' => 3, 'subscription' => 3, 'entry' => 0, 'entryState' => 0]],
        ]);

        $inspector = self::getContainer()->get(BackupInspector::class);
        self::assertInstanceOf(BackupInspector::class, $inspector);

        self::assertSame(2, $inspector->inspect($gzip)->subscriptions);
    }

    private function restoreInto(User $restorer, User $source, ?string $namedAs = null): void
    {
        $exporter = self::getContainer()->get(AccountBackupExporter::class);
        self::assertInstanceOf(AccountBackupExporter::class, $exporter);
        $foundation = null;
        $entryParts = [];
        foreach ($exporter->parts($source, 'https://source.example') as $part) {
            $gzip = $this->renamed($part->gzipBytes, $source, $namedAs);
            '000-foundation.ndjson.gz' === $part->memberName ? $foundation = $gzip : $entryParts[] = $gzip;
        }
        self::assertNotNull($foundation);

        $accountRestorer = self::getContainer()->get(AccountRestorer::class);
        self::assertInstanceOf(AccountRestorer::class, $accountRestorer);
        $accountRestorer->start($restorer, $foundation, 'REPLACE');
        $entryPartRestorer = self::getContainer()->get(EntryPartRestorer::class);
        self::assertInstanceOf(EntryPartRestorer::class, $entryPartRestorer);
        $reloaded = $this->entityManager->find(User::class, $restorer->requireId()) ?? $restorer;
        foreach ($entryParts as $entryPart) {
            $entryPartRestorer->load($reloaded, $entryPart);
        }
    }

    private function renamed(string $gzip, User $source, ?string $namedAs): string
    {
        if (null === $namedAs) {
            return $gzip;
        }

        $ndjson = str_replace(sprintf('saved:%d', $source->requireId()), $namedAs, (string) gzdecode($gzip));

        return (string) gzencode($ndjson);
    }

    private function savedFeedOf(User $user): Feed
    {
        $feed = new Feed(sprintf('saved:%d', $user->requireId()));
        $feed->setTitle('Saved from feeds');
        $feed->setSourceFormat(SourceFormat::SAVED);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-10-17 09:00:00')));
        $this->entityManager->flush();

        return $feed;
    }

    private function savedPostWithOrigin(User $user): void
    {
        $feed = $this->savedFeedOf($user);
        $entry = SavedPostFixtures::entry($this->entityManager, $feed, 'saved-post', 'https://heise.example/post');
        $origin = new EntryOrigin('Heise', 'https://heise.example/favicon.ico');
        $this->entityManager->persist(new SavedEntryOrigin($entry, $origin));
        $state = SavedPostFixtures::state($this->entityManager, $user, $entry);
        $state->markFavorite();
        $this->entityManager->flush();
    }

    /** @return array<string, mixed> */
    private function header(): array
    {
        return [
            'kind' => 'header',
            'schemaVersion' => 3,
            'createdAt' => '2026-08-17T09:00:00+00:00',
            'sourceUrl' => 'https://source.example',
            'sourceEmail' => 'source@example.com',
            'backupId' => 'backup-1',
            'part' => 0,
            'parts' => 1,
            'totals' => ['entries' => 0, 'entryStates' => 0],
        ];
    }

    /** @return array<string, mixed> */
    private function feedFields(): array
    {
        return ['siteUrl' => null, 'title' => null, 'description' => null, 'faviconUrl' => null, 'imageUrl' => null];
    }

    /** @return array<string, mixed> */
    private function subscriptionLine(string $feedUrl): array
    {
        return [
            'kind' => 'subscription', 'feedUrl' => $feedUrl, 'customTitle' => null, 'position' => 0,
            'markedReadUntil' => null, 'createdAt' => '2026-07-01T00:00:00+00:00', 'tags' => [],
            'includeInAllItems' => true, 'includeInForYou' => true,
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
}
