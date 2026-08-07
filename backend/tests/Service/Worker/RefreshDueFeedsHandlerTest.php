<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Fetch\BatchFeedFetcherInterface;
use App\Service\Fetch\FetchResponse;
use App\Service\Worker\Handler\RefreshDueFeedsHandler;
use App\Service\Worker\Message\RefreshDueFeeds;
use App\Tests\DbTestCase;
use App\Tests\Support\StubFeedFetcher;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Drives the handler through the container's real RefreshRunner, the same
 * "no mocks" stance as AdvanceRecommendationRunsHandlerTest -- the handler's
 * whole job is calling the runner with a fixed budget and logging its
 * report, so a mock would only re-encode that call. Only the outbound
 * fetcher is swapped, following the exact idiom
 * RefreshControllerTest::testPerFeedRefreshOfOwnFeedIsAccepted() uses to
 * keep a real refresh off the network.
 */
final class RefreshDueFeedsHandlerTest extends DbTestCase
{
    public function testFiringWithNoDueFeedsCompletesWithoutThrowing(): void
    {
        $this->handler()->__invoke(new RefreshDueFeeds());

        $this->addToAssertionCount(1);
    }

    public function testFiringRefreshesADueFeedAndMovesItsLastFetchedAt(): void
    {
        $feed = new Feed('https://example.com/due/feed.xml');
        $feed->setNextFetchAt(new \DateTimeImmutable('-1 hour'));
        $this->em->persist($feed);
        $subscriber = $this->user('sweeper@example.com');
        $this->em->persist(new Subscription($subscriber, $feed, new \DateTimeImmutable('-1 day')));
        $this->em->flush();
        self::assertNull($feed->getLastFetchedAt());

        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            $feed->getUrl(),
            FetchResponse::fetched($feed->getUrl(), false, $this->rss(), null, null),
        );
        // The runner's favicon phase fetches the feed's site homepage through
        // this same fetcher — stub the origin too, or it throws just as
        // loudly (same idiom as RefreshControllerTest).
        $fetcher->willReturn(
            'https://example.com',
            FetchResponse::fetched('https://example.com', false, '<html lang="en"></html>', null, null),
        );
        self::getContainer()->set(BatchFeedFetcherInterface::class, $fetcher);

        $this->handler()->__invoke(new RefreshDueFeeds());

        $this->em->clear();
        $refreshed = $this->em->getRepository(Feed::class)->find($feed->getId());
        self::assertNotNull($refreshed);
        self::assertNotNull($refreshed->getLastFetchedAt());
    }

    private function rss(): string
    {
        return /** @lang TEXT */ <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><title>Due</title>
            <item><title>Post</title><link>https://example.com/p</link><guid>due-1</guid></item>
            </channel></rss>
            XML;
    }

    private function user(string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function handler(): RefreshDueFeedsHandler
    {
        /** @var RefreshDueFeedsHandler $handler */
        $handler = self::getContainer()->get(RefreshDueFeedsHandler::class);

        return $handler;
    }
}
