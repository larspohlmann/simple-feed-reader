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
        // refresh.run.cache is a filesystem pool with a ten-minute TTL and
        // reused auto-increment ids, so a run left behind by an earlier test
        // process could be resumed by a same-id user here and corrupt the
        // `progress` assertions with no cause visible in this file. Not
        // reachable today — every test in this class ends `completed` and
        // TrackedRefreshRunner forgets a completed run — but cheap insurance.
        /** @var CacheItemPoolInterface $refreshRunCache */
        $refreshRunCache = self::getContainer()->get('test.cache.refresh_run');
        $refreshRunCache->clear();
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
        self::assertSame(['done' => 0, 'total' => 0], $body['progress']);
        // `total` was this slice's server-capped batch size sitting next to a
        // run-wide `remaining`, and dividing one by the other is issue #721. It is
        // gone, and this asserts it stays gone.
        self::assertArrayNotHasKey('total', $body);
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
        self::assertIsArray($body['progress']);
        self::assertIsInt($body['remaining']);
        // Asserted as the invariant rather than as two literals: these tests accept
        // either `completed` or `partial`, and a partial slice leaves feeds in
        // `remaining` that belong in the run's denominator.
        self::assertSame(1, $body['progress']['done']);
        self::assertSame(1 + $body['remaining'], $body['progress']['total']);
        self::assertArrayNotHasKey('total', $body);
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
        self::assertIsArray($body['progress']);
        self::assertIsInt($body['remaining']);
        // Asserted as the invariant rather than as two literals: these tests accept
        // either `completed` or `partial`, and a partial slice leaves feeds in
        // `remaining` that belong in the run's denominator.
        self::assertSame(1, $body['progress']['done']);
        self::assertSame(1 + $body['remaining'], $body['progress']['total']);
        self::assertArrayNotHasKey('total', $body);
    }
}
