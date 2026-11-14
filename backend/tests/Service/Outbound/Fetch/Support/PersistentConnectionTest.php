<?php

declare(strict_types=1);

namespace App\Tests\Service\Outbound\Fetch\Support;

use App\Service\Outbound\Fetch\Support\PersistentConnection;
use PHPUnit\Framework\TestCase;

final class PersistentConnectionTest extends TestCase
{
    public function testCapsTheConnectPhaseAndKeepsConnectionsPersistent(): void
    {
        self::assertSame(
            ['max_connect_duration' => 5.0, 'extra' => ['use_persistent_connections' => true]],
            PersistentConnection::options(),
        );
    }
}
