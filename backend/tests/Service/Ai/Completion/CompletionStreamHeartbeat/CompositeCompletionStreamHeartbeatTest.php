<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Completion\CompletionStreamHeartbeat;

use App\Service\Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatInterface;
use App\Service\Ai\Completion\CompletionStreamHeartbeat\CompositeCompletionStreamHeartbeat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositeCompletionStreamHeartbeat::class)]
final class CompositeCompletionStreamHeartbeatTest extends TestCase
{
    /**
     * The keepalive beats before the liveness marker; which service fills each slot is
     * CompletionStreamHeartbeatWiringTest's question.
     */
    public function testBeatingTheCompositeBeatsEveryMemberExactlyOnceInOrder(): void
    {
        /** @var list<string> $order */
        $order = [];

        $first = $this->createMock(CompletionStreamHeartbeatInterface::class);
        $first->expects($this->once())->method('beat')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'first';
        });

        $second = $this->createMock(CompletionStreamHeartbeatInterface::class);
        $second->expects($this->once())->method('beat')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'second';
        });

        $composite = new CompositeCompletionStreamHeartbeat($first, $second);

        $composite->beat();

        self::assertSame(['first', 'second'], $order);
    }
}
