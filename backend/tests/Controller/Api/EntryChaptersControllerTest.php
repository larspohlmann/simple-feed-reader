<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\EntryChapter;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Chapter\ChapterFileSource\ChapterFileSourceInterface;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EntryChaptersControllerTest extends WebTestCase
{
    public function testAYouTubeEntryServesItsChaptersAndATrimmedBody(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('chapters-youtube@example.com');
        $entryId = $this->seedEntry(
            $user,
            'https://www.youtube.com/watch?v=JsBZOcqZerk',
            '<p>0:00 A<br>1:00 B<br>2:00 C</p><p>Thanks</p>',
        );

        $client->request('GET', "/api/entries/$entryId", server: $headers);

        self::assertResponseIsSuccessful();
        $entry = $this->entryOf($client);
        self::assertSame(
            [
                ['startSeconds' => 0, 'title' => 'A'],
                ['startSeconds' => 60, 'title' => 'B'],
                ['startSeconds' => 120, 'title' => 'C'],
            ],
            $entry['chapters'],
        );
        self::assertSame('<p>Thanks</p>', $entry['contentHtml']);
    }

    public function testAnEntryWithoutChaptersSendsNull(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('chapters-none@example.com');
        $entryId = $this->seedEntry($user, 'https://blog.test/post', '<p>Plain</p>');

        $client->request('GET', "/api/entries/$entryId", server: $headers);

        self::assertNull($this->entryOf($client)['chapters']);
    }

    public function testAPendingChaptersFileIsFetchedOnceAndStored(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->auth('chapters-file@example.com');
        $entryId = $this->seedEntry($user, 'https://pod.test/ep1', '<p>Notes</p>', 'https://pod.test/ch.json');
        $files = $this->createMock(ChapterFileSourceInterface::class);
        $files->expects($this->once())->method('chaptersAt')
            ->willReturn([new EntryChapter(0, 'Intro'), new EntryChapter(60, 'Talk')]);
        self::getContainer()->set(ChapterFileSourceInterface::class, $files);

        $client->request('GET', "/api/entries/$entryId", server: $headers);
        $client->request('GET', "/api/entries/$entryId", server: $headers);

        self::assertSame(
            [['startSeconds' => 0, 'title' => 'Intro'], ['startSeconds' => 60, 'title' => 'Talk']],
            $this->entryOf($client)['chapters'],
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

    private function seedEntry(User $user, string $url, string $body, ?string $chaptersUrl = null): int
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
            $url,
            'Episode',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $entry->setContentHtml($body);
        $entry->declareChapters([], $chaptersUrl);
        $entityManager->persist($entry);
        $entityManager->flush();

        return $entry->requireId();
    }

    /** @return array{0: array<string,string>, 1: User} */
    private function auth(string $email): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($entityManager, $hasher))->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }
}
