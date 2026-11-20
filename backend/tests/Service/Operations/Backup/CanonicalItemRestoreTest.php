<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Backup;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Library\SavedPosts\Support\SavedFeedUrl;
use App\Service\Operations\Backup\AccountBackupExporter;
use App\Service\Operations\Backup\AccountRestorer;
use App\Service\Operations\Backup\EntryPartRestorer;
use App\Tests\DbTestCase;
use App\Tests\Support\CanonicalItems;
use App\Tests\Support\LemmyPostEntries;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CanonicalItemRestoreTest extends DbTestCase
{
    private UserFactory $userFactory;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->userFactory = new UserFactory($this->entityManager, $hasher);
    }

    public function testRestoredCopiesShareOneItem(): void
    {
        $user = $this->userFactory->create('canonical-restore@example.test');
        $this->persistCopiesOfOnePost($user);
        $parts = $this->exportParts($user);
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM feed');
        $connection->executeStatement('DELETE FROM canonical_item');
        $this->entityManager->clear();

        $this->restoreParts($user, $parts);

        $itemIds = array_map(
            static fn (mixed $itemId): ?string => null === $itemId ? null : (string) self::readNumeric($itemId),
            $connection->fetchFirstColumn('SELECT canonical_item_id FROM entry ORDER BY id'),
        );
        self::assertCount(3, $itemIds);
        self::assertNotNull($itemIds[0]);
        self::assertSame([$itemIds[0]], array_values(array_unique($itemIds)), 'every copy links to one item');
        self::assertSame(1, self::readNumeric($connection->fetchOne('SELECT COUNT(*) FROM canonical_item')));
    }

    private static function readNumeric(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    /** The post in an instance's Local and All feeds, and saved from Local. */
    private function persistCopiesOfOnePost(User $user): void
    {
        $local = $this->persistSubscribedFeed($user, 'https://lemmy.example/feeds/local.xml', SourceFormat::XML);
        $all = $this->persistSubscribedFeed($user, 'https://lemmy.example/feeds/all.xml', SourceFormat::XML);
        $saved = $this->persistSubscribedFeed(
            $user,
            SavedFeedUrl::buildUrl($user->requireId(), SocialNetwork::Lemmy),
            SourceFormat::SAVED,
        );
        $this->entityManager->flush();
        $savedGuid = LemmyPostEntries::PAGE . '#' . $local->requireId();
        foreach ([LemmyPostEntries::buildEntry($local), LemmyPostEntries::buildEntry($all)] as $copy) {
            $this->entityManager->persist($copy);
            CanonicalItems::linkAsIngested($this->entityManager, $copy);
        }
        $savedCopy = LemmyPostEntries::buildEntry($saved, guid: $savedGuid);
        $this->entityManager->persist($savedCopy);
        CanonicalItems::linkAsIngested($this->entityManager, $savedCopy);
        $this->entityManager->flush();
    }

    private function persistSubscribedFeed(User $user, string $url, string $sourceFormat): Feed
    {
        $feed = new Feed($url);
        $feed->setSourceFormat($sourceFormat);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-11-01 09:00:00')));

        return $feed;
    }

    /** @return array{string, list<string>} the foundation part and the entry parts */
    private function exportParts(User $user): array
    {
        $exporter = self::getContainer()->get(AccountBackupExporter::class);
        self::assertInstanceOf(AccountBackupExporter::class, $exporter);
        $foundation = '';
        $entryParts = [];
        foreach ($exporter->parts($user, 'https://source.example') as $part) {
            '000-foundation.ndjson.gz' === $part->memberName
                ? $foundation = $part->gzipBytes
                : $entryParts[] = $part->gzipBytes;
        }

        return [$foundation, $entryParts];
    }

    /** @param array{string, list<string>} $parts */
    private function restoreParts(User $user, array $parts): void
    {
        [$foundation, $entryParts] = $parts;
        $accountRestorer = self::getContainer()->get(AccountRestorer::class);
        self::assertInstanceOf(AccountRestorer::class, $accountRestorer);
        $reloaded = $this->entityManager->find(User::class, $user->requireId()) ?? $user;
        $accountRestorer->start($reloaded, $foundation, 'REPLACE');
        $entryPartRestorer = self::getContainer()->get(EntryPartRestorer::class);
        self::assertInstanceOf(EntryPartRestorer::class, $entryPartRestorer);
        foreach ($entryParts as $entryPart) {
            $reloaded = $this->entityManager->find(User::class, $user->requireId()) ?? $user;
            $entryPartRestorer->load($reloaded, $entryPart);
        }
    }
}
