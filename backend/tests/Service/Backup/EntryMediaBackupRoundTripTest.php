<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\Entry;
use App\Entity\EntryAttachment;
use App\Entity\EntryChapter;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Entity\ImageRendition;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryBatchInserter;
use App\Service\Backup\AccountBackupExporter;
use App\Service\Backup\Dto\EntryLine;
use App\Tests\DbTestCase;
use App\Tests\Support\EntryUrlHashes;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EntryMediaBackupRoundTripTest extends DbTestCase
{
    public function testEntryMediaSurvivesExportAndRestore(): void
    {
        $user = $this->makeUser('media-backup@example.com');
        $feed = new Feed('https://media.example/feed.xml');
        $this->entityManager->persist($feed);
        $entry = new Entry(
            $feed,
            'guid-media',
            'https://media.example/ep',
            'Episode',
            new \DateTimeImmutable('2026-08-02T00:00:00Z'),
            new \DateTimeImmutable('2026-08-02T00:00:00Z'),
        );
        $entry->getImage()->storePending('https://i/lead.jpg', 800, 600);
        $entry->setMedia(
            [new EntryMedium('https://i/lead.jpg', 'image', 800, 600)],
            [new EntryAttachment('https://cdn/ep.mp3', 'audio/mpeg', 3723, 4200000)],
        );
        $this->entityManager->persist($entry);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->entityManager->flush();

        $entryLine = $this->exportedEntryLine($user);
        self::assertSame(
            [['url' => 'https://i/lead.jpg', 'kind' => 'image', 'width' => 800, 'height' => 600]],
            $entryLine['media'],
        );
        self::assertSame(
            [[
                'url' => 'https://cdn/ep.mp3', 'mimeType' => 'audio/mpeg',
                'durationInSeconds' => 3723, 'sizeInBytes' => 4200000,
            ]],
            $entryLine['attachments'],
        );

        $target = new Feed('https://restore.example/feed.xml');
        $this->entityManager->persist($target);
        $this->entityManager->flush();
        $targetId = $target->getId();
        self::assertNotNull($targetId);

        $this->inserter()->insert($targetId, [EntryLine::fromLine($entryLine)]);

        $this->entityManager->clear();
        $restoredFeed = $this->entityManager
            ->getRepository(Feed::class)
            ->findOneBy(['url' => 'https://restore.example/feed.xml']);
        $restored = $this->entityManager->getRepository(Entry::class)->findOneBy(['feed' => $restoredFeed]);
        self::assertInstanceOf(Entry::class, $restored);

        $media = $restored->getMedia();
        self::assertCount(1, $media);
        self::assertSame('https://i/lead.jpg', $media[0]->url);
        self::assertSame(800, $media[0]->width);

        $attachments = $restored->getAttachments();
        self::assertCount(1, $attachments);
        self::assertSame('https://cdn/ep.mp3', $attachments[0]->url);
        self::assertSame(3723, $attachments[0]->durationInSeconds);
    }

    public function testImageRenditionsSurviveExportAndRestore(): void
    {
        $user = $this->makeUser('renditions-backup@example.com');
        $feed = new Feed('https://renditions.example/feed.xml');
        $this->entityManager->persist($feed);
        $entry = new Entry(
            $feed,
            'guid-renditions',
            'https://renditions.example/post',
            'Post',
            new \DateTimeImmutable('2026-10-01T00:00:00Z'),
            new \DateTimeImmutable('2026-10-01T00:00:00Z'),
        );
        $entry->getImage()->storePending('https://i/lead.jpg', 1200, 800);
        $entry->getImage()->storeRenditions([
            new ImageRendition('https://i/lead-600.jpg', 600),
            new ImageRendition('https://i/lead.jpg', 1200),
        ]);
        $this->entityManager->persist($entry);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->entityManager->flush();

        $entryLine = $this->exportedEntryLine($user);
        self::assertSame(
            [['url' => 'https://i/lead-600.jpg', 'width' => 600], ['url' => 'https://i/lead.jpg', 'width' => 1200]],
            $entryLine['imageRenditions'],
        );

        $target = new Feed('https://restore-renditions.example/feed.xml');
        $this->entityManager->persist($target);
        $this->entityManager->flush();
        $targetId = $target->requireId();

        $this->inserter()->insert($targetId, [EntryLine::fromLine($entryLine)]);

        $this->entityManager->clear();
        $restored = $this->entityManager->getRepository(Entry::class)->findOneBy(['feed' => $targetId]);
        self::assertInstanceOf(Entry::class, $restored);
        self::assertEquals(
            [new ImageRendition('https://i/lead-600.jpg', 600), new ImageRendition('https://i/lead.jpg', 1200)],
            $restored->getImage()->getRenditions(),
        );
    }

    public function testChaptersAndAPendingChaptersUrlSurviveExportAndRestore(): void
    {
        $user = $this->makeUser('chapters-backup@example.com');
        $feed = new Feed('https://chapters.example/feed.xml');
        $this->entityManager->persist($feed);
        $entry = new Entry(
            $feed,
            'guid-chapters',
            'https://chapters.example/inline',
            'Inline',
            new \DateTimeImmutable('2026-10-01T00:00:00Z'),
            new \DateTimeImmutable('2026-10-01T00:00:00Z'),
        );
        $entry->declareChapters([new EntryChapter(0, 'Intro'), new EntryChapter(90, 'Main')], null);
        $linked = new Entry(
            $feed,
            'guid-linked',
            'https://chapters.example/linked',
            'Linked',
            new \DateTimeImmutable('2026-10-02T00:00:00Z'),
            new \DateTimeImmutable('2026-10-02T00:00:00Z'),
        );
        $linked->declareChapters([], 'https://chapters.example/ch.json');
        $this->entityManager->persist($entry);
        $this->entityManager->persist($linked);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->entityManager->flush();

        $lines = $this->exportedEntryLines($user);
        self::assertSame(
            [['startSeconds' => 0, 'title' => 'Intro'], ['startSeconds' => 90, 'title' => 'Main']],
            $lines['guid-chapters']['chapters'],
        );
        self::assertNull($lines['guid-chapters']['chaptersUrl']);
        self::assertSame([], $lines['guid-linked']['chapters']);
        self::assertSame('https://chapters.example/ch.json', $lines['guid-linked']['chaptersUrl']);

        $target = new Feed('https://restore-chapters.example/feed.xml');
        $this->entityManager->persist($target);
        $this->entityManager->flush();
        $targetId = $target->requireId();

        $this->inserter()->insert($targetId, array_map(EntryLine::fromLine(...), array_values($lines)));

        $this->entityManager->clear();
        $restoredByGuid = [];
        foreach ($this->entityManager->getRepository(Entry::class)->findBy(['feed' => $targetId]) as $restored) {
            $restoredByGuid[$restored->getGuid()] = $restored;
        }
        self::assertEquals(
            [new EntryChapter(0, 'Intro'), new EntryChapter(90, 'Main')],
            $restoredByGuid['guid-chapters']->getChapters(),
        );
        self::assertNull($restoredByGuid['guid-chapters']->getChaptersUrl());
        self::assertSame([], $restoredByGuid['guid-linked']->getChapters());
        self::assertSame('https://chapters.example/ch.json', $restoredByGuid['guid-linked']->getChaptersUrl());
    }

    /** @return array<string, mixed> */
    private function exportedEntryLine(User $user): array
    {
        $lines = $this->exportedEntryLines($user);
        self::assertNotSame([], $lines, 'No entry line was exported.');

        return $lines[array_key_first($lines)];
    }

    /** @return array<string, array<string, mixed>> entry lines by guid */
    private function exportedEntryLines(User $user): array
    {
        $exporter = self::getContainer()->get(AccountBackupExporter::class);
        self::assertInstanceOf(AccountBackupExporter::class, $exporter);

        $entryLines = [];
        foreach ($exporter->parts($user, 'https://source.example') as $part) {
            if ('000-foundation.ndjson.gz' === $part->memberName) {
                continue;
            }
            foreach (explode("\n", (string) gzdecode($part->gzipBytes)) as $line) {
                if ('' === $line) {
                    continue;
                }
                $decoded = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded);
                if (($decoded['kind'] ?? null) === 'entry') {
                    /** @var array<string, mixed> $decoded */
                    self::assertIsString($decoded['guid']);
                    $entryLines[$decoded['guid']] = $decoded;
                }
            }
        }

        return $entryLines;
    }

    private function makeUser(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->entityManager, $hasher))->create($email, locale: 'de');
    }

    private function inserter(): EntryBatchInserter
    {
        return new EntryBatchInserter(
            $this->entityManager->getConnection(),
            EntryUrlHashes::production(),
        );
    }
}
