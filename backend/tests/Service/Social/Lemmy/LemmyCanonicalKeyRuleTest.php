<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy;

use App\Entity\Discussion;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\CanonicalItemKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Lemmy\LemmyCanonicalKeyRule;
use App\Service\Social\Lemmy\Support\LemmyProfileFeed;
use PHPUnit\Framework\TestCase;

final class LemmyCanonicalKeyRuleTest extends TestCase
{
    public function testApIdAndHomeRssPageShareOneKey(): void
    {
        $rule = new LemmyCanonicalKeyRule();

        $api = $rule->findKey($this->discussionEntry('https://lemmy.world/post/54626839', SocialNetwork::Lemmy));
        $rss = $rule->findKey($this->entry('https://lemmy.world/post/54626839', SocialNetwork::Lemmy));

        self::assertSame(CanonicalItemKind::Lemmy, $api?->kind);
        self::assertSame('https://lemmy.world/post/54626839', $api->key);
        self::assertSame($api->key, $rss?->key);
    }

    public function testForeignRssPageIsItsOwnKey(): void
    {
        $rule = new LemmyCanonicalKeyRule();

        $foreign = $rule->findKey($this->entry('https://lemmy.ml/post/5', SocialNetwork::Lemmy));
        $api = $rule->findKey($this->discussionEntry('https://lemmy.world/post/9', SocialNetwork::Lemmy));

        self::assertNotSame($api?->key, $foreign?->key);
    }

    public function testCommunityRssFeedCarriesTheLemmyNetwork(): void
    {
        self::assertNotNull(LemmyProfileFeed::parseIdentity('https://lemmy.world/feeds/c/technology.xml'));
    }

    public function testADiscussionThreadOnAFeedWithoutNetworkStillHasAKey(): void
    {
        $key = new LemmyCanonicalKeyRule()->findKey($this->discussionEntry('https://lemmy.world/post/7', null));

        self::assertSame('https://lemmy.world/post/7', $key?->key);
    }

    public function testSavedCopyGuidKeysAsItsOriginal(): void
    {
        $entry = $this->entry('https://lemmy.world/post/7#42', SocialNetwork::Lemmy);
        $key = new LemmyCanonicalKeyRule()->findKey($entry);

        self::assertSame('https://lemmy.world/post/7', $key?->key);
    }

    public function testTheGuidWinsOverTheDiscussionAddress(): void
    {
        $entry = $this->entry('https://lemmy.world/post/7', SocialNetwork::Lemmy);
        $entry->setDiscussion(Discussion::platformThread('https://lemmy.ml/post/9'));

        self::assertSame('https://lemmy.world/post/7', new LemmyCanonicalKeyRule()->findKey($entry)?->key);
    }

    public function testEntryOfAnotherNetworkHasNoKey(): void
    {
        $entry = $this->entry('https://lemmy.world/post/7', SocialNetwork::Mastodon);

        self::assertNull(new LemmyCanonicalKeyRule()->findKey($entry));
    }

    public function testArticleFeedHasNoKey(): void
    {
        self::assertNull(new LemmyCanonicalKeyRule()->findKey($this->entry('https://lemmy.world/post/7', null)));
    }

    private function entry(string $guid, ?SocialNetwork $network): Entry
    {
        $feed = new Feed('https://example.com/feed');
        $feed->classifyAs($network);
        $now = new \DateTimeImmutable('2026-01-01');

        return new Entry($feed, $guid, 'https://example.com/story', 'Title', $now, $now);
    }

    private function discussionEntry(string $postUrl, ?SocialNetwork $network): Entry
    {
        $feed = new Feed('https://example.com/feed');
        $feed->classifyAs($network);
        $now = new \DateTimeImmutable('2026-01-01');
        $entry = new Entry($feed, $postUrl, null, 'Title', $now, $now);
        $entry->setDiscussion(Discussion::platformThread($postUrl));

        return $entry;
    }
}
