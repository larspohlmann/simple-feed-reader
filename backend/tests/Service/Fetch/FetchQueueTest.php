<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\FetchAttempt;
use App\Service\Fetch\FetchQueue;
use App\Service\Fetch\FetchTicket;
use PHPUnit\Framework\TestCase;

final class FetchQueueTest extends TestCase
{
    /** @param array<int|string, FetchTicket> $tickets */
    private function queue(array $tickets): FetchQueue
    {
        return new FetchQueue(new \ArrayIterator($tickets));
    }

    public function testDrainsTicketsInOrderAndKeepsTheirKeys(): void
    {
        $queue = $this->queue([
            11 => new FetchTicket('https://one.example.com/feed'),
            22 => new FetchTicket('https://two.example.com/feed'),
        ]);

        self::assertTrue($queue->hasMore());
        $first = $queue->next();
        self::assertSame(11, $first->key);
        self::assertSame('https://one.example.com/feed', $first->url);

        $second = $queue->next();
        self::assertSame(22, $second->key);

        self::assertFalse($queue->hasMore());
    }

    public function testARequeuedRedirectIsServedBeforeUnstartedTickets(): void
    {
        $queue = $this->queue([
            11 => new FetchTicket('https://one.example.com/feed'),
            22 => new FetchTicket('https://two.example.com/feed'),
        ]);

        $first = $queue->next();
        $queue->requeue($first->followedTo('https://one.example.com/moved', permanent: true));

        $served = $queue->next();
        self::assertSame(11, $served->key);
        self::assertSame('https://one.example.com/moved', $served->url);
    }

    public function testDrainingContinuesWithUnstartedTicketsAfterAContinuation(): void
    {
        $queue = $this->queue([
            11 => new FetchTicket('https://one.example.com/feed'),
            22 => new FetchTicket('https://two.example.com/feed'),
        ]);

        $first = $queue->next();
        $queue->requeue($first->followedTo('https://one.example.com/moved', permanent: true));

        $queue->next();

        $next = $queue->next();
        self::assertSame(22, $next->key);

        self::assertFalse($queue->hasMore());
    }

    public function testMultipleRequeuedRedirectsAreServedInTheOrderTheyWereRequeued(): void
    {
        $queue = $this->queue([
            11 => new FetchTicket('https://one.example.com/feed'),
            22 => new FetchTicket('https://two.example.com/feed'),
            33 => new FetchTicket('https://three.example.com/feed'),
        ]);

        $first = $queue->next();
        $second = $queue->next();
        $third = $queue->next();

        $queue->requeue($first->followedTo('https://one.example.com/moved', permanent: true));
        $queue->requeue($second->followedTo('https://two.example.com/moved', permanent: true));
        $queue->requeue($third->followedTo('https://three.example.com/moved', permanent: true));

        self::assertSame(11, $queue->next()->key);
        self::assertSame(22, $queue->next()->key);
        self::assertSame(33, $queue->next()->key);
    }

    public function testAnEmptyQueueHasNoMore(): void
    {
        self::assertFalse($this->queue([])->hasMore());
    }

    public function testNextOnAnExhaustedQueueIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);

        $this->queue([])->next();
    }
}
