<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\Entry;
use App\Entity\EntryAttachment;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Backup\AccountBackupExporter;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Backup\EntryBatchInserter;
use App\Service\Url\UrlNormalizer;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EntryMediaBackupRoundTripTest extends DbTestCase
{
    public function testEntryMediaSurvivesExportAndRestore(): void
    {
        $user = $this->makeUser('media-backup@example.com');
        $feed = new Feed('https://media.example/feed.xml');
        $this->em->persist($feed);
        $entry = new Entry(
            $feed,
            'guid-media',
            'https://media.example/ep',
            'Episode',
            new \DateTimeImmutable('2026-08-02T00:00:00Z'),
            new \DateTimeImmutable('2026-08-02T00:00:00Z'),
        );
        $entry->setImage('https://i/lead.jpg', 800, 600);
        $entry->setMedia(
            [new EntryMedium('https://i/lead.jpg', 'image', 800, 600)],
            [new EntryAttachment('https://cdn/ep.mp3', 'audio/mpeg', 3723, 4200000)],
        );
        $this->em->persist($entry);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->flush();

        $entryLine = $this->exportedEntryLine($user);
        self::assertSame(
            [['url' => 'https://i/lead.jpg', 'kind' => 'image', 'width' => 800, 'height' => 600]],
            $entryLine['media'],
        );
        self::assertSame(
            [['url' => 'https://cdn/ep.mp3', 'mimeType' => 'audio/mpeg', 'durationInSeconds' => 3723, 'sizeInBytes' => 4200000]],
            $entryLine['attachments'],
        );

        $target = new Feed('https://restore.example/feed.xml');
        $this->em->persist($target);
        $this->em->flush();
        $targetId = $target->getId();
        self::assertNotNull($targetId);

        (new EntryBatchInserter($this->em->getConnection(), new UrlNormalizer()))
            ->insert($targetId, [EntryLine::fromLine($entryLine)]);

        $this->em->clear();
        $restoredFeed = $this->em->getRepository(Feed::class)->findOneBy(['url' => 'https://restore.example/feed.xml']);
        $restored = $this->em->getRepository(Entry::class)->findOneBy(['feed' => $restoredFeed]);
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

    /** @return array<string, mixed> */
    private function exportedEntryLine(User $user): array
    {
        $exporter = self::getContainer()->get(AccountBackupExporter::class);
        self::assertInstanceOf(AccountBackupExporter::class, $exporter);

        foreach ($exporter->lines($user, 'https://source.example') as $raw) {
            $decoded = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            if (($decoded['kind'] ?? null) === 'entry') {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        self::fail('No entry line was exported.');
    }

    private function makeUser(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email, locale: 'de');
    }
}
