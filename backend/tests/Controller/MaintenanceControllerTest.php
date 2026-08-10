<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Fetch\BatchFeedFetcherInterface;
use App\Service\Fetch\FetchResponse;
use App\Tests\Support\StubFeedFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Lock\LockFactory;

final class MaintenanceControllerTest extends WebTestCase
{
    /**
     * Subscribed, not just persisted: `/maintenance/refresh` always prunes
     * (#246), so an unsubscribed feed would be swept before this test's
     * fetcher stub ever sees it.
     */
    private function feedFor(KernelBrowser $client, string $url): Feed
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $feed = new Feed($url);
        $feed->setNextFetchAt(new \DateTimeImmutable('-1 hour'));
        $em->persist($feed);
        $subscriber = new User('maintenance-fixture-subscriber@example.com', new \DateTimeImmutable());
        $em->persist($subscriber);
        $em->persist(new Subscription($subscriber, $feed, new \DateTimeImmutable()));
        $em->flush();

        return $feed;
    }

    public function testRejectsMissingToken(): void
    {
        $client = self::createClient();
        $client->request('POST', '/maintenance/refresh');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRejectsWrongToken(): void
    {
        $client = self::createClient();
        $client->request('POST', '/maintenance/refresh?token=wrong');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRefreshesWithValidToken(): void
    {
        $client = self::createClient();
        $feed = $this->feedFor($client, 'https://maint.example.com/feed');

        $stub = new StubFeedFetcher();
        $stub->willReturn($feed->getUrl(), FetchResponse::notModified($feed->getUrl(), false, null, null));
        // The runner's favicon phase fetches the site homepage through the
        // same fetcher — stub the origin too, or it throws just as loudly.
        $stub->willReturn(
            'https://maint.example.com',
            FetchResponse::fetched('https://maint.example.com', false, '<html lang="en"></html>', null, null),
        );
        self::getContainer()->set(BatchFeedFetcherInterface::class, $stub);

        // token from .env.test
        $client->request('POST', '/maintenance/refresh?token=test-maintenance-token');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        /** @var array{status: string, notModified: int} $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('completed', $payload['status']);
        self::assertSame(1, $payload['notModified']);
    }

    public function testAcceptsTokenViaHeader(): void
    {
        $client = self::createClient();
        $feed = $this->feedFor($client, 'https://maint.example.com/feed');

        $stub = new StubFeedFetcher();
        $stub->willReturn($feed->getUrl(), FetchResponse::notModified($feed->getUrl(), false, null, null));
        // The runner's favicon phase fetches the site homepage through the
        // same fetcher — stub the origin too, or it throws just as loudly.
        $stub->willReturn(
            'https://maint.example.com',
            FetchResponse::fetched('https://maint.example.com', false, '<html lang="en"></html>', null, null),
        );
        self::getContainer()->set(BatchFeedFetcherInterface::class, $stub);

        $client->request('POST', '/maintenance/refresh', server: [
            'HTTP_X_MAINTENANCE_TOKEN' => 'test-maintenance-token',
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testRejectsWrongTokenInHeader(): void
    {
        $client = self::createClient();
        $client->request('POST', '/maintenance/refresh', server: [
            'HTTP_X_MAINTENANCE_TOKEN' => 'nope',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testGetMethodIsNotAllowed(): void
    {
        $client = self::createClient();
        $client->request('GET', '/maintenance/refresh?token=test-maintenance-token');

        self::assertResponseStatusCodeSame(405);
    }

    public function testBusyReturnsConflict(): void
    {
        $client = self::createClient();
        $this->feedFor($client, 'https://maint.example.com/feed');

        /** @var LockFactory $lockFactory */
        $lockFactory = self::getContainer()->get(LockFactory::class);
        $lock = $lockFactory->createLock('feed-refresh');
        self::assertTrue($lock->acquire());

        $client->request('POST', '/maintenance/refresh?token=test-maintenance-token');

        self::assertResponseStatusCodeSame(409);
        $lock->release();
    }

    public function testUnknownActionIs404(): void
    {
        $client = self::createClient();
        $client->request('POST', '/maintenance/wipe-everything?token=test-maintenance-token');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRecommendationSweepRejectsMissingToken(): void
    {
        $client = self::createClient();
        $client->request('POST', '/maintenance/recommendations/sweep');

        self::assertResponseStatusCodeSame(403);
        // The body shape matters, not just the status: the guard's rejection
        // carries the `error` field the caller reads.
        self::assertSame(
            ['error' => 'forbidden'],
            json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testRecommendationSweepRunsWithValidToken(): void
    {
        $client = self::createClient();

        $client->request('POST', '/maintenance/recommendations/sweep', server: [
            'HTTP_X_MAINTENANCE_TOKEN' => 'test-maintenance-token',
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        // Shared SQLite DB: other test classes may have left runs, so assert
        // the report's shape, not exact zero counts. Left untyped (not the
        // array{...} shape used elsewhere in this file) so PHPStan does not
        // treat the assertIsInt() calls below as already-proven and flag them
        // as redundant (staticMethod.alreadyNarrowedType) — they are the point
        // of this test.
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsInt($payload['startedRuns']);
        self::assertIsInt($payload['advancedRuns']);
        self::assertIsInt($payload['activeRuns']);
    }

    public function testRecommendationSweepGetMethodIsNotAllowed(): void
    {
        $client = self::createClient();
        $client->request('GET', '/maintenance/recommendations/sweep?token=test-maintenance-token');

        self::assertResponseStatusCodeSame(405);
    }

    public function testTickRejectsMissingToken(): void
    {
        $client = self::createClient();
        $client->request('POST', '/maintenance/tick');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(
            ['error' => 'forbidden'],
            json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testTickGetMethodIsNotAllowed(): void
    {
        $client = self::createClient();
        $client->request('GET', '/maintenance/tick?token=test-maintenance-token');

        self::assertResponseStatusCodeSame(405);
    }

    public function testTickReturnsBothHalvesWithValidToken(): void
    {
        $client = self::createClient();
        $feed = $this->feedFor($client, 'https://maint.example.com/feed');

        $stub = new StubFeedFetcher();
        $stub->willReturn($feed->getUrl(), FetchResponse::notModified($feed->getUrl(), false, null, null));
        $stub->willReturn(
            'https://maint.example.com',
            FetchResponse::fetched('https://maint.example.com', false, '<html lang="en"></html>', null, null),
        );
        self::getContainer()->set(BatchFeedFetcherInterface::class, $stub);

        $client->request('POST', '/maintenance/tick', server: [
            'HTTP_X_MAINTENANCE_TOKEN' => 'test-maintenance-token',
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('refresh', $payload);
        self::assertArrayHasKey('recommendations', $payload);
        /** @var array<string, mixed> $refresh */
        $refresh = $payload['refresh'];
        self::assertSame('completed', $refresh['status']);
        /** @var array<string, mixed> $recommendations */
        $recommendations = $payload['recommendations'];
        self::assertIsInt($recommendations['activeRuns']);
    }
}
