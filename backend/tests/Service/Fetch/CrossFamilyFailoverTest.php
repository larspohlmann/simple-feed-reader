<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\CrossFamilyFailover;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;

final class CrossFamilyFailoverTest extends TestCase
{
    public function testAConnectionResetWarrantsAnotherFamily(): void
    {
        self::assertTrue(CrossFamilyFailover::isWarranted(new TransportException('Connection reset by peer')));
    }

    public function testATimeoutDoesNotWarrantAnotherFamily(): void
    {
        // A timeout means the family answered the connect but is slow, not that
        // the route is dead; re-driving every family would only multiply the wait.
        self::assertFalse(CrossFamilyFailover::isWarranted(new TimeoutException('Idle timeout reached')));
    }

    public function testAnAbsentTransportErrorWarrantsNothing(): void
    {
        self::assertFalse(CrossFamilyFailover::isWarranted(null));
    }
}
