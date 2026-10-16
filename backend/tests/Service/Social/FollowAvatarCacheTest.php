<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Social\FollowAvatarCache;
use App\Service\Social\Model\FollowedAccountModel;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class FollowAvatarCacheTest extends TestCase
{
    use AssignsEntityIds;

    private FollowAvatarCache $cache;

    protected function setUp(): void
    {
        $this->cache = new FollowAvatarCache(new ArrayAdapter());
    }

    public function testABlueskyAccountsAvatarsAreFoundAgain(): void
    {
        $account = self::blueskyAccount(3);

        $this->cache->remember($account, [self::follow('did:plc:bob')]);

        self::assertSame('https://cdn.test/did:plc:bob.jpg', $this->cache->find($account)->avatarOf('did:plc:bob'));
    }

    public function testAnUnaskedAccountHasNone(): void
    {
        self::assertTrue($this->cache->find(self::blueskyAccount(3))->isEmpty());
    }

    public function testEachAccountKeepsItsOwnAvatars(): void
    {
        $this->cache->remember(self::blueskyAccount(3), [self::follow('did:plc:bob')]);

        self::assertTrue($this->cache->find(self::blueskyAccount(4))->isEmpty());
    }

    public function testAMastodonAccountRemembersNothing(): void
    {
        $account = self::withId(SocialAccounts::mastodon(), 5);

        $this->cache->remember($account, [self::follow('bob@mastodon.example')]);

        self::assertTrue($this->cache->find($account)->isEmpty());
    }

    public function testALaterLoadReplacesTheEarlierOne(): void
    {
        $account = self::blueskyAccount(3);
        $this->cache->remember($account, [self::follow('did:plc:bob')]);

        $this->cache->remember($account, [self::follow('did:plc:carol')]);

        self::assertNull($this->cache->find($account)->avatarOf('did:plc:bob'));
        self::assertSame('https://cdn.test/did:plc:carol.jpg', $this->cache->find($account)->avatarOf('did:plc:carol'));
    }

    private static function blueskyAccount(int $id): SocialAccount
    {
        return self::withId(SocialAccounts::bluesky(), $id);
    }

    private static function follow(string $identity): FollowedAccountModel
    {
        return new FollowedAccountModel(
            $identity,
            '@' . $identity,
            null,
            'https://bsky.app/profile/' . $identity . '/rss',
            'https://cdn.test/' . $identity . '.jpg',
        );
    }
}
