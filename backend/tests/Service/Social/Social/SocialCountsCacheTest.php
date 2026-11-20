<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Entity\CanonicalItem;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\CanonicalItemKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\Model\SocialCountsModel;
use App\Service\Social\Social\SocialCountsCache;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialRuleWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class SocialCountsCacheTest extends TestCase
{
    use AssignsEntityIds;

    private const string ADDRESS = 'https://mastodon.social/@Gargron/110';
    private const string URI = 'https://mastodon.social/users/Gargron/statuses/110';

    private SocialCountsCache $cache;

    protected function setUp(): void
    {
        $this->cache = new SocialCountsCache(new ArrayAdapter(), SocialRuleWiring::postAddresses());
    }

    public function testAnUnaskedPostHasNoCounts(): void
    {
        self::assertNull($this->cache->find(self::post(SocialNetwork::Mastodon, self::ADDRESS)));
    }

    public function testRememberedCountsAreFoundAgain(): void
    {
        $this->cache->remember(self::post(SocialNetwork::Mastodon, self::ADDRESS), self::counts());

        self::assertEquals(
            new SocialCountsModel(5, 2, 1, null),
            $this->cache->find(self::post(SocialNetwork::Mastodon, self::ADDRESS)),
        );
    }

    public function testTheSameAddressOnAnotherNetworkIsAnotherPost(): void
    {
        $this->cache->remember(self::post(SocialNetwork::Mastodon, self::ADDRESS), self::counts());

        self::assertNull($this->cache->find(self::post(SocialNetwork::Bluesky, self::ADDRESS)));
    }

    public function testForgottenCountsAreGone(): void
    {
        $this->cache->remember(self::post(SocialNetwork::Mastodon, self::ADDRESS), self::counts());

        $this->cache->forget(self::post(SocialNetwork::Mastodon, self::ADDRESS));

        self::assertNull($this->cache->find(self::post(SocialNetwork::Mastodon, self::ADDRESS)));
    }

    public function testCopiesOfOneItemShareTheirCountsWhateverAddressEachCarries(): void
    {
        $item = self::withId(new CanonicalItem(CanonicalItemKind::Mastodon, self::URI), 7);
        $rssCopy = self::post(SocialNetwork::Mastodon, self::ADDRESS);
        $rssCopy->linkTo($item);
        $uriCopy = self::post(SocialNetwork::Mastodon, self::URI);
        $uriCopy->linkTo($item);

        $this->cache->remember($rssCopy, new SocialCountsModel(5, 2, 1, null));

        self::assertEquals(new SocialCountsModel(5, 2, 1, null), $this->cache->find($uriCopy));
        $this->cache->forget($uriCopy);
        self::assertNull($this->cache->find($rssCopy));
    }

    public function testAnEntryThatIsNoPostHasNoCountsToKeep(): void
    {
        $article = self::post(null, self::ADDRESS);

        $this->cache->remember($article, new SocialCountsModel(5, 2, 1, null));

        self::assertNull($this->cache->find($article));
    }

    private static function counts(): SocialCountsModel
    {
        return new SocialCountsModel(5, 2, 1, null);
    }

    private static function post(?SocialNetwork $network, string $address): Entry
    {
        $feed = new Feed('https://feeds.example/' . (null === $network ? 'articles' : $network->value) . '.xml');
        $feed->classifyAs($network);
        $published = new \DateTimeImmutable('2026-11-20 10:00:00');

        return new Entry($feed, $address, $address, 'Post', $published, $published);
    }
}
