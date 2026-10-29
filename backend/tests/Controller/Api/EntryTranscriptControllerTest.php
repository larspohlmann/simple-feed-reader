<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\TranscriptParagraph;
use App\Entity\User;
use App\Enum\TranscriptFormat;
use App\Service\Transcript\Exception\TranscriptUnreachableException;
use App\Service\Transcript\TranscriptSource\TranscriptSourceInterface;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\ScriptedTranscriptSource;
use App\Tests\Support\TranscriptFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class EntryTranscriptControllerTest extends ApiTestCase
{
    private const string TRANSCRIPT_URL = 'https://pod.test/ep.vtt';

    public function testAFilledTranscriptIsServed(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('transcript-filled@example.com');
        $entryId = $this->seedEntry($user);
        $this->transcriptOf($entryId)->fill([new TranscriptParagraph(0, 'Ann', 'Hi.')], 'Hi.');
        $this->flush();

        $client->request('GET', "/api/entries/$entryId", server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['language' => 'de', 'paragraphs' => [['startSeconds' => 0, 'speaker' => 'Ann', 'text' => 'Hi.']]],
            $this->entryOf($client)['transcript'],
        );
    }

    public function testAnEntryWithoutATranscriptSendsNull(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('transcript-none@example.com');
        $entryId = $this->seedEntry($user);

        $client->request('GET', "/api/entries/$entryId", server: $headers);

        self::assertResponseIsSuccessful();
        self::assertNull($this->entryOf($client)['transcript']);
    }

    public function testAFailedTranscriptSendsNullWithoutFetching(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->bearerAndUser('transcript-failed@example.com');
        $entryId = $this->seedEntry($user);
        $this->transcriptOf($entryId)->fail();
        $this->flush();
        $source = $this->createMock(TranscriptSourceInterface::class);
        $source->expects($this->never())->method('bodyAt');
        self::getContainer()->set(TranscriptSourceInterface::class, $source);

        $client->request('GET', "/api/entries/$entryId", server: $headers);

        self::assertResponseIsSuccessful();
        self::assertNull($this->entryOf($client)['transcript']);
    }

    public function testAPendingTranscriptIsFetchedOnOpenAndStored(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->bearerAndUser('transcript-pending@example.com');
        $entryId = $this->seedEntry($user);
        $this->transcriptOf($entryId);
        $source = ScriptedTranscriptSource::answering(TranscriptFixtures::READABLE);
        self::getContainer()->set(TranscriptSourceInterface::class, $source);

        $client->request('GET', "/api/entries/$entryId", server: $headers);
        $client->request('GET', "/api/entries/$entryId", server: $headers);

        self::assertSame([self::TRANSCRIPT_URL], $source->fetchedUrls);
        self::assertSame(
            ['language' => 'de', 'paragraphs' => [['startSeconds' => 1, 'speaker' => null, 'text' => 'Hallo.']]],
            $this->entryOf($client)['transcript'],
        );
    }

    public function testAPendingTranscriptThatCannotBeFetchedSendsNullAndStaysPending(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->bearerAndUser('transcript-unreachable@example.com');
        $entryId = $this->seedEntry($user);
        $this->transcriptOf($entryId);
        $source = ScriptedTranscriptSource::answering(TranscriptFixtures::READABLE)->failingAt(
            self::TRANSCRIPT_URL,
            TranscriptUnreachableException::answered(self::TRANSCRIPT_URL, Response::HTTP_SERVICE_UNAVAILABLE),
        );
        self::getContainer()->set(TranscriptSourceInterface::class, $source);

        $client->request('GET', "/api/entries/$entryId", server: $headers);

        self::assertResponseIsSuccessful();
        self::assertNull($this->entryOf($client)['transcript']);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertSame(
            'pending',
            $entityManager->getConnection()->fetchOne('SELECT status FROM entry_transcript'),
        );
    }

    /** @return array<mixed> */
    private function entryOf(KernelBrowser $client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entry']);

        return $body['entry'];
    }

    private function seedEntry(User $user): int
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $feed = new Feed('https://example.com/feed-' . uniqid('', true) . '.xml');
        $feed->setTitle('Seeded');
        $entityManager->persist($feed);
        $entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $entry = new Entry(
            $feed,
            'guid-' . uniqid('', true),
            'https://pod.test/ep',
            'Episode',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $entry->setContentHtml('<p>Notes</p>');
        $entityManager->persist($entry);
        $entityManager->flush();

        return $entry->requireId();
    }

    private function transcriptOf(int $entryId): EntryTranscript
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entry = $entityManager->find(Entry::class, $entryId);
        self::assertInstanceOf(Entry::class, $entry);
        $transcript = new EntryTranscript($entry, self::TRANSCRIPT_URL, TranscriptFormat::Vtt, 'de');
        $entityManager->persist($transcript);
        $entityManager->flush();

        return $transcript;
    }

    private function flush(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->flush();
    }
}
