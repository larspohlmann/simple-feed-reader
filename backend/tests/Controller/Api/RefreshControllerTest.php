<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Tests\Support\StubFeedFetcher;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RefreshControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        /** @var CacheItemPoolInterface $rateLimiterCache */
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        $rateLimiterCache->clear();
        // refresh.run.cache is a filesystem pool and ids are reused, so a run another test process left could be
        // resumed here and corrupt `progress`. Cheap insurance.
        /** @var CacheItemPoolInterface $refreshRunCache */
        $refreshRunCache = self::getContainer()->get('test.cache.refresh_run');
        $refreshRunCache->clear();
        self::ensureKernelShutdown();
    }

    /** @return array<string, string> */
    private function auth(string $email): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $factory = new UserFactory($entityManager, self::getContainer()->get('security.user_password_hasher'));
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($factory->create($email));

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/refresh');
        self::assertResponseStatusCodeSame(401);
    }

    public function testRefreshWithNoFeedsReportsCompleted(): void
    {
        $client = self::createClient();
        $headers = $this->auth('norefresh@example.com');
        $client->request('POST', '/api/refresh', server: $headers);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('completed', $body['status']);
        self::assertSame(['done' => 0, 'total' => 0], $body['progress']);
        // No `total`: a slice's capped batch size beside a run-wide `remaining` invited dividing the two (#721).
        self::assertArrayNotHasKey('total', $body);
    }

    public function testPerFeedRefreshOfANonSubscribedFeedIs404(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $feed = new Feed('https://example.com/notmine.xml');
        $entityManager->persist($feed);
        $entityManager->flush();

        $headers = $this->auth('nosub@example.com');
        $client->request('POST', '/api/refresh?feedId=' . $feed->getId(), server: $headers);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(
            '"detail":"No such subscription."',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testPerFeedRefreshOfOwnFeedIsAccepted(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $factory = new UserFactory($entityManager, self::getContainer()->get('security.user_password_hasher'));
        $user = $factory->create('owner3@example.com');
        $feed = new Feed('https://example.com/mine.xml');
        $entityManager->persist($feed);
        $entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z')));
        $entityManager->flush();

        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        // Swap in a stub fetcher so no real network I/O happens.
        $fetcher = new StubFeedFetcher();
        // StubFeedFetcher throws LogicException on an unstubbed URL; stub the one feed as not-modified.
        $fetcher->willReturn(
            'https://example.com/mine.xml',
            FetchResponseModel::notModified('https://example.com/mine.xml', false, null, null),
        );
        // The runner's favicon phase fetches the feed's site homepage through
        // this same fetcher — stub the origin too, or it throws just as loudly.
        $fetcher->willReturn(
            'https://example.com',
            FetchResponseModel::fetched('https://example.com', false, '<html lang="en"></html>', null, null),
        );
        self::getContainer()->set(BatchFeedFetcherInterface::class, $fetcher);

        $client->request(
            'POST',
            '/api/refresh?feedId=' . $feed->getId(),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertReportsARunOfOneFeed($body);
    }

    public function testTagRefreshOfAForeignTagIs404(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $factory = new UserFactory($entityManager, self::getContainer()->get('security.user_password_hasher'));
        $owner = $factory->create('tagowner@example.com');
        $stranger = $factory->create('tagstranger@example.com');
        $tag = new Tag($owner, 'news');
        $entityManager->persist($tag);
        $entityManager->flush();

        // The stranger asking to refresh a tag they do not own must get a 404,
        // mirroring the per-feed IDOR guard (not 403 — do not confirm it exists).
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($stranger);
        $client->request(
            'POST',
            '/api/refresh?tag=' . $tag->getId(),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testTagRefreshOfAnUnknownTagIs404(): void
    {
        $client = self::createClient();
        $headers = $this->auth('unknowntag@example.com');
        $client->request('POST', '/api/refresh?tag=999999', server: $headers);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('"detail":"No such tag."', (string) $client->getResponse()->getContent());
    }

    public function testTagRefreshOfOwnTagIsAccepted(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $factory = new UserFactory($entityManager, self::getContainer()->get('security.user_password_hasher'));
        $user = $factory->create('tagowner2@example.com');
        $tag = new Tag($user, 'news');
        $entityManager->persist($tag);
        $feed = new Feed('https://example.com/tagged.xml');
        $entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $subscription->addTag($tag);
        $entityManager->persist($subscription);
        $entityManager->flush();

        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://example.com/tagged.xml',
            FetchResponseModel::notModified('https://example.com/tagged.xml', false, null, null),
        );
        // The runner's favicon phase fetches the feed's site homepage through
        // this same fetcher — stub the origin too, or it throws just as loudly.
        $fetcher->willReturn(
            'https://example.com',
            FetchResponseModel::fetched('https://example.com', false, '<html lang="en"></html>', null, null),
        );
        self::getContainer()->set(BatchFeedFetcherInterface::class, $fetcher);

        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $client->request(
            'POST',
            '/api/refresh?tag=' . $tag->getId(),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertReportsARunOfOneFeed($body);
    }

    /**
     * Both scopes sweep one feed, so both must claim a run of one: asserted as an invariant because either may answer
     * `completed` or `partial`, and a partial slice leaves feeds in `remaining`.
     */
    private function assertReportsARunOfOneFeed(mixed $body): void
    {
        self::assertIsArray($body);
        self::assertContains($body['status'], ['completed', 'partial']);
        self::assertIsArray($body['progress']);
        self::assertIsInt($body['remaining']);
        self::assertSame(1, $body['progress']['done']);
        self::assertSame(1 + $body['remaining'], $body['progress']['total']);
        self::assertArrayNotHasKey('total', $body);
    }
}
