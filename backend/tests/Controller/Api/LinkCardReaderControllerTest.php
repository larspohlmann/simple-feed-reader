<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCardContent;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\LinkCardRepository;
use App\Service\Reader\ArticleExtractor\ArticleExtractorInterface;
use App\Service\Reader\Model\ExtractionFailure;
use App\Service\Reader\Model\ExtractionResultModel;
use App\Tests\Support\FakeArticleExtractor;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class LinkCardReaderControllerTest extends WebTestCase
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
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($entityManager, $hasher))->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    private function spendReaderBudget(User $user): void
    {
        $readerLimiter = self::getContainer()->get('limiter.reader');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $readerLimiter);
        $readerLimiter->create('user-' . $user->requireId())->consume(60);
    }

    private function installFake(): FakeArticleExtractor
    {
        $fake = new FakeArticleExtractor();
        self::getContainer()->set(ArticleExtractorInterface::class, $fake);

        return $fake;
    }

    private function seedEntryWithCard(User $user, ?string $imageUrl = 'https://example.com/card.jpg'): Entry
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $feed = new Feed('https://example.com/feed-' . uniqid('', true) . '.xml');
        $feed->setTitle('Seeded');
        $entityManager->persist($feed);
        $entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $entry = new Entry(
            $feed,
            'g-' . uniqid('', true),
            'https://social.example/post/1',
            'Post',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $entityManager->persist($entry);
        $linkCards = self::getContainer()->get(LinkCardRepository::class);
        self::assertInstanceOf(LinkCardRepository::class, $linkCards);
        $linkCards->append($entry, [], [
            new LinkCardContent('https://example.com/article', 'Card title', null, $imageUrl, 'Example'),
        ]);
        $entityManager->flush();

        return $entry;
    }

    /** @return array<mixed> */
    private function bodyOf(KernelBrowser $client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    public function testExtractsTheCardsPage(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('card-reader-ok@example.com');
        $fake = $this->installFake();
        $fake->willReturn(ExtractionResultModel::ok(
            url: 'https://example.com/article',
            title: 'Extracted',
            byline: null,
            siteName: 'Example',
            contentHtml: '<p>Body</p>',
            excerpt: null,
        ));
        $entry = $this->seedEntryWithCard($user);

        $client->request('GET', '/api/entries/' . $entry->requireId() . '/link-cards/0/reader', server: $headers);

        self::assertResponseIsSuccessful();
        $body = $this->bodyOf($client);
        self::assertSame('ok', $body['status']);
        self::assertSame('<p>Body</p>', $body['contentHtml']);
        self::assertSame(['https://example.com/article'], $fake->calls);
    }

    public function testAFailedExtractionLeadsWithTheCardImage(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('card-reader-fail@example.com');
        $this->installFake()->willReturn(ExtractionResultModel::failed(
            'https://example.com/article',
            ExtractionFailure::Fetch,
            'HTTP 403 Forbidden',
        ));
        $entry = $this->seedEntryWithCard($user);

        $client->request('GET', '/api/entries/' . $entry->requireId() . '/link-cards/0/reader', server: $headers);

        $body = $this->bodyOf($client);
        self::assertSame('failed', $body['status']);
        self::assertSame(
            ['url' => 'https://example.com/card.jpg', 'width' => null, 'height' => null],
            $body['originalHero'],
        );
    }

    public function testAnUnknownPositionIsNotFoundAndSpendsNoBudget(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('card-reader-position@example.com');
        $fake = $this->installFake();
        $entry = $this->seedEntryWithCard($user);
        $this->spendReaderBudget($user);

        $client->request('GET', '/api/entries/' . $entry->requireId() . '/link-cards/1/reader', server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame([], $fake->calls);
    }

    public function testAnotherUsersCardIsNotFound(): void
    {
        $client = self::createClient();
        [, $owner] = $this->auth('card-reader-owner@example.com');
        [$headers] = $this->auth('card-reader-stranger@example.com');
        $fake = $this->installFake();
        $entry = $this->seedEntryWithCard($owner);

        $client->request('GET', '/api/entries/' . $entry->requireId() . '/link-cards/0/reader', server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame([], $fake->calls);
    }

    public function testSharesTheEntryReadersBudget(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('card-reader-limit@example.com');
        $fake = $this->installFake();
        $entry = $this->seedEntryWithCard($user);
        $this->spendReaderBudget($user);

        $client->request('GET', '/api/entries/' . $entry->requireId() . '/link-cards/0/reader', server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame([], $fake->calls);
    }

    public function testRequiresAToken(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/entries/1/link-cards/0/reader');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}
