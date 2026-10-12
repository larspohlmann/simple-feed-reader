<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Social\BlueskySessionCache;
use App\Service\Social\Crypto\BlueskySessionCipher;
use App\Service\Social\Model\BlueskySessionModel;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

final class BlueskySessionCacheTest extends TestCase
{
    use AssignsEntityIds;

    public function testARememberedSessionLivesAnHourAndAHalf(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('set')->willReturnSelf();
        $item->expects($this->once())->method('expiresAfter')->with(5400);
        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);
        $account = SocialAccounts::bluesky();
        self::assignId($account, 3);

        (new BlueskySessionCache($pool, new BlueskySessionCipher(new InstanceSecretCipher(str_repeat('k', 32)))))
            ->remember($account, new BlueskySessionModel('jwt', 'alice.bsky.social'));
    }
}
