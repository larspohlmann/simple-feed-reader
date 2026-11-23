<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Refresh\Pass;

use App\Service\Ingestion\Refresh\Pass\FeedClaims;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class FeedClaimsTest extends TestCase
{
    private FeedClaims $first;
    private FeedClaims $second;

    protected function setUp(): void
    {
        $store = new InMemoryStore();
        $this->first = new FeedClaims(new LockFactory($store));
        $this->second = new FeedClaims(new LockFactory($store));
    }

    public function testASecondPassCannotClaimAFeedTheFirstHolds(): void
    {
        self::assertTrue($this->first->claim(7));
        self::assertFalse($this->second->claim(7));
        self::assertTrue($this->second->claim(8));
    }

    public function testAReleasedFeedCanBeClaimedAgain(): void
    {
        $this->first->claim(7);
        $this->first->release(7);

        self::assertTrue($this->second->claim(7));
    }

    public function testReleaseAllFreesEveryClaim(): void
    {
        $this->first->claim(7);
        $this->first->claim(8);
        $this->first->releaseAll();

        self::assertTrue($this->second->claim(7));
        self::assertTrue($this->second->claim(8));
    }
}
