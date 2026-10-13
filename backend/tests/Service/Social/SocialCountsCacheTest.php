<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Enum\SocialNetwork;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\SocialCountsCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class SocialCountsCacheTest extends TestCase
{
    private const string ADDRESS = 'https://mastodon.social/@Gargron/110';

    private SocialCountsCache $cache;

    protected function setUp(): void
    {
        $this->cache = new SocialCountsCache(new ArrayAdapter());
    }

    public function testAnUnaskedPostHasNoCounts(): void
    {
        self::assertNull($this->cache->find(self::post(SocialNetwork::Mastodon)));
    }

    public function testRememberedCountsAreFoundAgain(): void
    {
        $this->cache->remember(self::post(SocialNetwork::Mastodon), new SocialCountsModel(5, 2, 1, null));

        self::assertEquals(
            new SocialCountsModel(5, 2, 1, null),
            $this->cache->find(self::post(SocialNetwork::Mastodon)),
        );
    }

    public function testTheSameAddressOnAnotherNetworkIsAnotherPost(): void
    {
        $this->cache->remember(self::post(SocialNetwork::Mastodon), new SocialCountsModel(5, 2, 1, null));

        self::assertNull($this->cache->find(self::post(SocialNetwork::Bluesky)));
    }

    public function testForgottenCountsAreGone(): void
    {
        $this->cache->remember(self::post(SocialNetwork::Mastodon), new SocialCountsModel(5, 2, 1, null));

        $this->cache->forget(self::post(SocialNetwork::Mastodon));

        self::assertNull($this->cache->find(self::post(SocialNetwork::Mastodon)));
    }

    private static function post(SocialNetwork $network): SocialPostAddressModel
    {
        return new SocialPostAddressModel($network, self::ADDRESS);
    }
}
