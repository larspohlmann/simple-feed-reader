<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Enum\ProxyType;
use App\Service\Fetch\FetchAttempt;
use App\Service\Fetch\FetchQueue;
use App\Service\Fetch\FetchTicket;
use App\Service\Fetch\HostSlots;
use App\Service\Fetch\ProxyConfig;
use PHPUnit\Framework\TestCase;

final class FetchQueueTest extends TestCase
{
    /**
     * @param array<int|string, FetchTicket> $tickets
     */
    private function queue(
        array $tickets,
        ?ProxyConfig $batchProxy = null,
        ?HostSlots $hostSlots = null,
        int $lookAhead = 100,
    ): FetchQueue {
        return new FetchQueue(
            new \ArrayIterator($tickets),
            $hostSlots ?? new HostSlots(100),
            $lookAhead,
            $batchProxy,
        );
    }

    private function attempt(string $url): FetchAttempt
    {
        return FetchAttempt::start(0, new FetchTicket($url));
    }

    private function runnable(FetchQueue $queue): FetchAttempt
    {
        $attempt = $queue->takeRunnable();
        self::assertNotNull($attempt);

        return $attempt;
    }

    public function testDrainsTicketsInOrderAndKeepsTheirKeys(): void
    {
        $queue = $this->queue([
            11 => new FetchTicket('https://one.example.com/feed'),
            22 => new FetchTicket('https://two.example.com/feed'),
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
            11 => new FetchTicket('https://one.example.com/feed'),
            22 => new FetchTicket('https://two.example.com/feed'),
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
            11 => new FetchTicket('https://one.example.com/feed'),
            22 => new FetchTicket('https://two.example.com/feed'),
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
            11 => new FetchTicket('https://one.example.com/feed'),
            22 => new FetchTicket('https://two.example.com/feed'),
            33 => new FetchTicket('https://three.example.com/feed'),
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
            yield 11 => new FetchTicket('https://one.example.com/feed');
            $resumptions[] = 2;
            yield 22 => new FetchTicket('https://two.example.com/feed');
        })();

        $queue = new FetchQueue($tickets, new HostSlots(100), 100);
        $queue->takeRunnable();

        // Only the first yield has run: pulling one ticket must not resume the
        // generator body up to the second yield's deadline check.
        self::assertSame([1], $resumptions);
    }

    public function testStampsTheBatchProxyOnToEveryAttemptItStarts(): void
    {
        $proxy = new ProxyConfig(ProxyType::Socks5, 'proxy.example.com', 1080, null, null);
        $queue = $this->queue([
            11 => new FetchTicket('https://one.example.com/feed'),
            22 => new FetchTicket('https://two.example.com/feed'),
        ], batchProxy: $proxy);

        self::assertSame($proxy, $this->runnable($queue)->proxy);
        self::assertSame($proxy, $this->runnable($queue)->proxy);
    }

    public function testStartsDirectAttemptsWhenNoProxyIsResolved(): void
    {
        $queue = $this->queue([11 => new FetchTicket('https://one.example.com/feed')]);

        self::assertNull($this->runnable($queue)->proxy);
    }

    public function testParksATicketWhoseHostIsFullAndServesAFreeHostFirst(): void
    {
        $hostSlots = new HostSlots(1);
        $hostSlots->acquire($this->attempt('https://busy.example.com/inflight'));

        $queue = $this->queue([
            11 => new FetchTicket('https://busy.example.com/feed'),
            22 => new FetchTicket('https://free.example.com/feed'),
        ], hostSlots: $hostSlots);

        // The busy host is at capacity, so its ticket is parked and the free
        // host jumps ahead of it.
        self::assertSame(22, $this->runnable($queue)->key);
        // Nothing else can run while the busy host stays full.
        self::assertNull($queue->takeRunnable());
    }

    public function testAParkedTicketIsServedOnceItsHostFrees(): void
    {
        $hostSlots = new HostSlots(1);
        $inFlight = $this->attempt('https://busy.example.com/inflight');
        $hostSlots->acquire($inFlight);

        $queue = $this->queue([
            11 => new FetchTicket('https://busy.example.com/feed'),
        ], hostSlots: $hostSlots);

        self::assertNull($queue->takeRunnable(), 'parked while the host is full');

        $hostSlots->release($inFlight);

        self::assertSame(11, $this->runnable($queue)->key, 'served once the host frees');
        // Served means removed from the park: it is not handed out a second time.
        self::assertNull($queue->takeRunnable());
    }

    public function testLookAheadPastAFullHostIsBounded(): void
    {
        $hostSlots = new HostSlots(1);
        $hostSlots->acquire($this->attempt('https://busy.example.com/inflight'));

        $pulled = [];
        $tickets = (function () use (&$pulled): \Generator {
            foreach ([11, 22, 33, 44] as $key) {
                $pulled[] = $key;
                yield $key => new FetchTicket('https://busy.example.com/feed' . $key);
            }
        })();

        // Look-ahead of two: the queue parks at most two full-host tickets before
        // it gives up rather than draining the whole budgeted generator.
        $queue = new FetchQueue($tickets, $hostSlots, 2);

        self::assertNull($queue->takeRunnable());
        self::assertSame([11, 22], $pulled);
    }
}
