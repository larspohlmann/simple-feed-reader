<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\CompletionStreamHeartbeat;
use App\Service\Recommendation\CompositeCompletionStreamHeartbeat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositeCompletionStreamHeartbeat::class)]
final class CompositeCompletionStreamHeartbeatTest extends TestCase
{
    public function testBeatingTheCompositeBeatsEveryMemberExactlyOnceInOrder(): void
    {
        /** @var list<string> $order */
        $order = [];

        $first = $this->createMock(CompletionStreamHeartbeat::class);
        $first->expects(self::once())->method('beat')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'first';
        });

        $second = $this->createMock(CompletionStreamHeartbeat::class);
        $second->expects(self::once())->method('beat')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'second';
        });

        $composite = new CompositeCompletionStreamHeartbeat([$first, $second]);

        $composite->beat();

        self::assertSame(['first', 'second'], $order);
    }
}
