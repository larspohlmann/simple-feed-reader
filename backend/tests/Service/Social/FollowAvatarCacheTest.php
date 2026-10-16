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

    private ArrayAdapter $pool;

    private FollowAvatarCache $cache;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
        $this->cache = new FollowAvatarCache($this->pool);
    }

    public function testABlueskyAccountsAvatarsAreFoundAgain(): void
    {
        $account = self::blueskyAccount(3);

        $this->cache->remember($account, [self::follow('did:plc:bob')]);

        self::assertSame('https://cdn.test/did:plc:bob.jpg', $this->cache->find($account)->avatarOf('did:plc:bob'));
    }

    public function testAnUnaskedAccountHasNone(): void
    {
        self::assertSame([], $this->cache->find(self::blueskyAccount(3))->toCacheEntry());
    }

    public function testEachAccountKeepsItsOwnAvatars(): void
    {
        $this->cache->remember(self::blueskyAccount(3), [self::follow('did:plc:bob')]);

        self::assertSame([], $this->cache->find(self::blueskyAccount(4))->toCacheEntry());
    }

    public function testAMastodonAccountRemembersNothing(): void
    {
        $account = self::withId(SocialAccounts::mastodon(), 5);

        $this->cache->remember($account, [self::follow('bob@mastodon.example')]);

        self::assertSame([], $this->cache->find($account)->toCacheEntry());
    }

    public function testALaterLoadReplacesTheEarlierOne(): void
    {
        $account = self::blueskyAccount(3);
        $this->cache->remember($account, [self::follow('did:plc:bob')]);

        $this->cache->remember($account, [self::follow('did:plc:carol')]);

        self::assertNull($this->cache->find($account)->avatarOf('did:plc:bob'));
        self::assertSame('https://cdn.test/did:plc:carol.jpg', $this->cache->find($account)->avatarOf('did:plc:carol'));
    }

    public function testARememberedAccountIsKeptUnderItsId(): void
    {
        $this->cache->remember(self::blueskyAccount(3), [self::follow('did:plc:bob')]);

        self::assertTrue($this->pool->hasItem('follow_avatars.3'));
    }

    public function testAMastodonAccountLeavesNothingInThePool(): void
    {
        $this->cache->remember(self::withId(SocialAccounts::mastodon(), 5), [self::follow('bob@mastodon.example')]);

        self::assertFalse($this->pool->hasItem('follow_avatars.5'));
    }

    public function testAMastodonAccountFindsNoneEvenWhereAnEntryIsStored(): void
    {
        $this->cache->remember(self::blueskyAccount(5), [self::follow('did:plc:bob')]);

        self::assertSame([], $this->cache->find(self::withId(SocialAccounts::mastodon(), 5))->toCacheEntry());
    }

    public function testADamagedEntryFindsNone(): void
    {
        $item = $this->pool->getItem('follow_avatars.3');
        $item->set('damaged');
        $this->pool->save($item);

        self::assertSame([], $this->cache->find(self::blueskyAccount(3))->toCacheEntry());
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
