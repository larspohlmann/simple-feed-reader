<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Service\Fetch\BatchFeedFetcherInterface;
use App\Service\Fetch\FetchResponse;
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
        self::ensureKernelShutdown();
    }

    /** @return array<string, string> */
    private function auth(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $factory = new UserFactory($em, self::getContainer()->get('security.user_password_hasher'));
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
        self::assertSame(0, $body['total']);
    }

    public function testPerFeedRefreshOfANonSubscribedFeedIs404(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $feed = new Feed('https://example.com/notmine.xml');
        $em->persist($feed);
        $em->flush();

        $headers = $this->auth('nosub@example.com');
        $client->request('POST', '/api/refresh?feedId=' . $feed->getId(), server: $headers);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPerFeedRefreshOfOwnFeedIsAccepted(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $factory = new UserFactory($em, self::getContainer()->get('security.user_password_hasher'));
        $user = $factory->create('owner3@example.com');
        $feed = new Feed('https://example.com/mine.xml');
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z')));
        $em->flush();

        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        // Swap in a stub fetcher so no real network I/O happens.
        $fetcher = new StubFeedFetcher();
        // StubFeedFetcher throws LogicException on an unstubbed URL; stub the one feed as not-modified.
        $fetcher->willReturn(
            'https://example.com/mine.xml',
            FetchResponse::notModified('https://example.com/mine.xml', false, null, null),
        );
        // The runner's favicon phase fetches the feed's site homepage through
        // this same fetcher — stub the origin too, or it throws just as loudly.
        $fetcher->willReturn(
            'https://example.com',
            FetchResponse::fetched('https://example.com', false, '<html lang="en"></html>', null, null),
        );
        self::getContainer()->set(BatchFeedFetcherInterface::class, $fetcher);

        $client->request(
            'POST',
            '/api/refresh?feedId=' . $feed->getId(),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertContains($body['status'], ['completed', 'partial']);
        self::assertSame(1, $body['total']);
    }

    public function testTagRefreshOfAForeignTagIs404(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $factory = new UserFactory($em, self::getContainer()->get('security.user_password_hasher'));
        $owner = $factory->create('tagowner@example.com');
        $stranger = $factory->create('tagstranger@example.com');
        $tag = new Tag($owner, 'news');
        $em->persist($tag);
        $em->flush();

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
    }

    public function testTagRefreshOfOwnTagIsAccepted(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $factory = new UserFactory($em, self::getContainer()->get('security.user_password_hasher'));
        $user = $factory->create('tagowner2@example.com');
        $tag = new Tag($user, 'news');
        $em->persist($tag);
        $feed = new Feed('https://example.com/tagged.xml');
        $em->persist($feed);
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $sub->addTag($tag);
        $em->persist($sub);
        $em->flush();

        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://example.com/tagged.xml',
            FetchResponse::notModified('https://example.com/tagged.xml', false, null, null),
        );
        // The runner's favicon phase fetches the feed's site homepage through
        // this same fetcher — stub the origin too, or it throws just as loudly.
        $fetcher->willReturn(
            'https://example.com',
            FetchResponse::fetched('https://example.com', false, '<html lang="en"></html>', null, null),
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
        self::assertIsArray($body);
        self::assertContains($body['status'], ['completed', 'partial']);
        self::assertSame(1, $body['total']);
    }
}
