<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\ProviderCallHeartbeat;

use App\Service\Recommendation\Run\ProviderCallHeartbeat\CompositeProviderCallHeartbeat;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\ProviderCallHeartbeatInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositeProviderCallHeartbeat::class)]
final class CompositeProviderCallHeartbeatTest extends TestCase
{
    /**
     * The keepalive beats before the liveness marker; which service fills each slot is
     * ProviderCallHeartbeatWiringTest's question.
     */
    public function testBeatingTheCompositeBeatsEveryMemberExactlyOnceInOrder(): void
    {
        /** @var list<string> $order */
        $order = [];

        $first = $this->createMock(ProviderCallHeartbeatInterface::class);
        $first->expects($this->once())->method('beat')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'first';
        });

        $second = $this->createMock(ProviderCallHeartbeatInterface::class);
        $second->expects($this->once())->method('beat')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'second';
        });

        $composite = new CompositeProviderCallHeartbeat($first, $second);

        $composite->beat();

        self::assertSame(['first', 'second'], $order);
    }
}
