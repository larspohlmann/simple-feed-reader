<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Pass;

use App\Enum\ProxyType;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\ProxyConfigModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Pass\FetchQueue;
use App\Service\Fetch\Pass\RequestSlots;
use App\Tests\Support\FeedTickets;
use PHPUnit\Framework\TestCase;

final class FetchQueueTest extends TestCase
{
    /**
     * @param array<int|string, RequestTicketModel> $tickets
     */
    private function queue(
        array $tickets,
        ?ProxyConfigModel $batchProxy = null,
        ?RequestSlots $slots = null,
        int $lookAhead = 100,
    ): FetchQueue {
        return new FetchQueue(
            new \ArrayIterator($tickets),
            $slots ?? new RequestSlots(),
            $lookAhead,
            $batchProxy,
        );
    }

    private function attempt(string $url): FetchAttemptModel
    {
        return FetchAttemptModel::start(0, FeedTickets::of($url));
    }

    /** @phpstan-impure */
    private function runnable(FetchQueue $queue): FetchAttemptModel
    {
        $attempt = $queue->takeRunnable();
        self::assertNotNull($attempt);

        return $attempt;
    }

    public function testDrainsTicketsInOrderAndKeepsTheirKeys(): void
    {
        $queue = $this->queue([
            11 => FeedTickets::of('https://one.example.com/feed'),
            22 => FeedTickets::of('https://two.example.com/feed'),
        ]);

        $first = $this->runnable($queue);
        self::assertSame(11, $first->key);
        self::assertSame('https://one.example.com/feed', $first->url);

        self::assertSame(22, $this->runnable($queue)->key);

        self::assertNull($queue->takeRunnable());
    }

    public function testARequeuedRedirectIsServedBeforeUnstartedTickets(): void
    {
        $queue = $this->queue([
            11 => FeedTickets::of('https://one.example.com/feed'),
            22 => FeedTickets::of('https://two.example.com/feed'),
        ]);

        $first = $this->runnable($queue);
        $queue->requeue($first->followedTo('https://one.example.com/moved', permanent: true));

        $served = $this->runnable($queue);
        self::assertSame(11, $served->key);
        self::assertSame('https://one.example.com/moved', $served->url);
    }

    public function testDrainingContinuesWithUnstartedTicketsAfterAContinuation(): void
    {
        $queue = $this->queue([
            11 => FeedTickets::of('https://one.example.com/feed'),
            22 => FeedTickets::of('https://two.example.com/feed'),
        ]);

        $first = $this->runnable($queue);
        $queue->requeue($first->followedTo('https://one.example.com/moved', permanent: true));

        $queue->takeRunnable();

        self::assertSame(22, $this->runnable($queue)->key);

        self::assertNull($queue->takeRunnable());
    }

    public function testMultipleRequeuedRedirectsAreServedInTheOrderTheyWereRequeued(): void
    {
        $queue = $this->queue([
            11 => FeedTickets::of('https://one.example.com/feed'),
            22 => FeedTickets::of('https://two.example.com/feed'),
            33 => FeedTickets::of('https://three.example.com/feed'),
        ]);

        $first = $this->runnable($queue);
        $second = $this->runnable($queue);
        $third = $this->runnable($queue);

        $queue->requeue($first->followedTo('https://one.example.com/moved', permanent: true));
        $queue->requeue($second->followedTo('https://two.example.com/moved', permanent: true));
        $queue->requeue($third->followedTo('https://three.example.com/moved', permanent: true));

        self::assertSame(11, $this->runnable($queue)->key);
        self::assertSame(22, $this->runnable($queue)->key);
        self::assertSame(33, $this->runnable($queue)->key);
    }

    public function testAnEmptyQueueYieldsNothing(): void
    {
        self::assertNull($this->queue([])->takeRunnable());
    }

    public function testTheTicketSourceIsNotAdvancedUntilTheNextItemIsWanted(): void
    {
        $resumptions = [];
        $tickets = (function () use (&$resumptions): \Generator {
            $resumptions[] = 1;
            yield 11 => FeedTickets::of('https://one.example.com/feed');
            $resumptions[] = 2;
            yield 22 => FeedTickets::of('https://two.example.com/feed');
        })();

        $queue = new FetchQueue($tickets, new RequestSlots(), 100);
        $queue->takeRunnable();

        // Only the first yield has run: pulling one ticket must not resume the
        // generator body up to the second yield's deadline check.
        self::assertSame([1], $resumptions);
    }

    public function testStampsTheBatchProxyOnToEveryAttemptItStarts(): void
    {
        $proxy = new ProxyConfigModel(ProxyType::Socks5, 'proxy.example.com', 1080, null, null);
        $queue = $this->queue([
            11 => FeedTickets::of('https://one.example.com/feed'),
            22 => FeedTickets::of('https://two.example.com/feed'),
        ], batchProxy: $proxy);

        self::assertSame($proxy, $this->runnable($queue)->proxy);
        self::assertSame($proxy, $this->runnable($queue)->proxy);
    }

    public function testStartsDirectAttemptsWhenNoProxyIsResolved(): void
    {
        $queue = $this->queue([11 => FeedTickets::of('https://one.example.com/feed')]);

        self::assertNull($this->runnable($queue)->proxy);
    }

    public function testAFollowUpGoesAheadOfUnstartedTicketsAsAFreshAttemptOverTheBatchProxy(): void
    {
        $proxy = new ProxyConfigModel(ProxyType::Socks5, 'proxy.example.com', 1080, null, null);
        $queue = $this->queue([
            11 => FeedTickets::of('https://one.example.com/feed'),
            22 => FeedTickets::of('https://two.example.com/feed'),
        ], batchProxy: $proxy);
        $this->runnable($queue);

        $queue->followUp(11, FeedTickets::of('https://one.example.com/again'));
        $followUp = $this->runnable($queue);

        self::assertSame(11, $followUp->key);
        self::assertSame('https://one.example.com/again', $followUp->url);
        self::assertTrue($followUp->isFirstSend());
        self::assertSame($proxy, $followUp->proxy);
    }

    public function testAFollowUpNeverAdvancesTheTicketSource(): void
    {
        $resumptions = [];
        $tickets = (static function () use (&$resumptions): \Generator {
            $resumptions[] = 1;
            yield 11 => FeedTickets::of('https://one.example.com/feed');
            $resumptions[] = 2;
            yield 22 => FeedTickets::of('https://two.example.com/feed');
        })();
        $queue = new FetchQueue($tickets, new RequestSlots(), 100);
        $queue->takeRunnable();

        $queue->followUp(11, FeedTickets::of('https://one.example.com/again'));
        $queue->takeRunnable();

        self::assertSame([1], $resumptions);
    }

    public function testParksATicketWhoseHostIsFullAndServesAFreeHostFirst(): void
    {
        $slots = new RequestSlots();
        $slots->acquire($this->attempt('https://busy.example.com/inflight'));

        $queue = $this->queue([
            11 => FeedTickets::cappedAt('https://busy.example.com/feed', 1),
            22 => FeedTickets::of('https://free.example.com/feed'),
        ], slots: $slots);

        // The busy host is at capacity, so its ticket is parked and the free
        // host jumps ahead of it.
        self::assertSame(22, $this->runnable($queue)->key);
        // Nothing else can run while the busy host stays full.
        self::assertNull($queue->takeRunnable());
    }

    public function testAParkedTicketIsServedOnceItsHostFrees(): void
    {
        $slots = new RequestSlots();
        $inFlight = $this->attempt('https://busy.example.com/inflight');
        $slots->acquire($inFlight);

        $queue = $this->queue([
            11 => FeedTickets::cappedAt('https://busy.example.com/feed', 1),
        ], slots: $slots);

        self::assertNull($queue->takeRunnable(), 'parked while the host is full');

        $slots->release($inFlight);

        self::assertSame(11, $this->runnable($queue)->key, 'served once the host frees');
        // Served means removed from the park: it is not handed out a second time.
        self::assertNull($queue->takeRunnable());
    }

    public function testLookAheadPastAFullHostIsBounded(): void
    {
        $slots = new RequestSlots();
        $slots->acquire($this->attempt('https://busy.example.com/inflight'));

        $pulled = [];
        $tickets = (function () use (&$pulled): \Generator {
            foreach ([11, 22, 33, 44] as $key) {
                $pulled[] = $key;
                yield $key => FeedTickets::cappedAt('https://busy.example.com/feed' . $key, 1);
            }
        })();

        // Look-ahead of two: the queue parks at most two full-host tickets before
        // it gives up rather than draining the whole budgeted generator.
        $queue = new FetchQueue($tickets, $slots, 2);

        self::assertNull($queue->takeRunnable());
        self::assertSame([11, 22], $pulled);
    }
}
