<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Reader\ArticleExtractorInterface;
use App\Service\Reader\ExtractionResult;
use App\Tests\Support\FakeArticleExtractor;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EntryReaderControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        // The reader limiter counts in a FILESYSTEM pool that outlives the run,
        // so a prior case's spend must not bleed into this one and trip a 429.
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    /** @return array{0: array<string,string>, 1: User} */
    private function auth(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($em, $hasher))->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    private function seedEntry(User $user, ?string $url): Entry
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $feed = new Feed('https://example.com/feed-' . uniqid('', true) . '.xml');
        $feed->setTitle('Seeded');
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $entry = new Entry(
            $feed,
            'g-' . uniqid('', true),
            $url,
            'Post',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $em->persist($entry);
        $em->flush();

        return $entry;
    }

    private function installFake(): FakeArticleExtractor
    {
        $fake = new FakeArticleExtractor();
        self::getContainer()->set(ArticleExtractorInterface::class, $fake);

        return $fake;
    }

    public function testOwnedEntryOkReturnsExtractedArticle(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('reader-ok@example.com');
        $fake = $this->installFake();
        $fake->willReturn(ExtractionResult::ok(
            url: 'https://example.com/article',
            title: 'The Title',
            byline: 'A. Writer',
            siteName: 'Example',
            contentHtml: '<p>Body</p>',
            excerpt: 'An excerpt.',
        ));
        $entry = $this->seedEntry($user, 'https://example.com/article');

        $client->request('GET', '/api/entries/' . $entry->getId() . '/reader', server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('ok', $body['status']);
        self::assertSame('The Title', $body['title']);
        self::assertSame('<p>Body</p>', $body['contentHtml']);
        self::assertSame('A. Writer', $body['byline']);
        self::assertSame('Example', $body['siteName']);
        self::assertSame('An excerpt.', $body['excerpt']);
        self::assertSame('https://example.com/article', $body['url']);
        self::assertArrayHasKey('extractedAt', $body);
        self::assertSame(['https://example.com/article'], $fake->calls);
    }

    public function testOwnedEntryFetchFailureReturnsFailedStatus(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('reader-fail@example.com');
        $fake = $this->installFake();
        $fake->willReturn(ExtractionResult::failed('https://example.com/article', 'fetch'));
        $entry = $this->seedEntry($user, 'https://example.com/article');

        $client->request('GET', '/api/entries/' . $entry->getId() . '/reader', server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('failed', $body['status']);
        self::assertSame('fetch', $body['reason']);
        self::assertSame(['https://example.com/article'], $fake->calls);
    }

    public function testEntryWithoutUrlShortCircuitsWithoutCallingExtractor(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('reader-nourl@example.com');
        $fake = $this->installFake();
        $entry = $this->seedEntry($user, null);

        $client->request('GET', '/api/entries/' . $entry->getId() . '/reader', server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('failed', $body['status']);
        self::assertSame('no_url', $body['reason']);
        self::assertNull($body['url']);
        self::assertSame([], $fake->calls);
    }

    public function testEntryOfAnotherUserIs404AndDoesNotCallExtractor(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('reader-idor@example.com');
        [, $stranger] = $this->auth('reader-owner@example.com');
        $fake = $this->installFake();
        $entry = $this->seedEntry($stranger, 'https://example.com/article');

        $client->request('GET', '/api/entries/' . $entry->getId() . '/reader', server: $headers);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $fake->calls);
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/entries/1/reader');
        self::assertResponseStatusCodeSame(401);
    }
}
