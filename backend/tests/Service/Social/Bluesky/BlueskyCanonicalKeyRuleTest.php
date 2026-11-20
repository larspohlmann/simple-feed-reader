<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Bluesky;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\CanonicalItemKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\BlueskyCanonicalKeyRule;
use PHPUnit\Framework\TestCase;

final class BlueskyCanonicalKeyRuleTest extends TestCase
{
    private const string AT_URI = 'at://did:plc:abc/app.bsky.feed.post/3k';

    public function testAtUriIsTheKey(): void
    {
        $key = new BlueskyCanonicalKeyRule()->findKey($this->entry(self::AT_URI, SocialNetwork::Bluesky));

        self::assertSame(CanonicalItemKind::Bluesky, $key?->kind);
        self::assertSame(self::AT_URI, $key->key);
    }

    public function testSavedCopyGuidKeysAsItsOriginal(): void
    {
        $key = new BlueskyCanonicalKeyRule()->findKey($this->entry(self::AT_URI . '#42', SocialNetwork::Bluesky));

        self::assertSame(self::AT_URI, $key?->key);
    }

    public function testAGuidThatIsNoAtUriHasNoKey(): void
    {
        $entry = $this->entry('https://example.com/a', SocialNetwork::Bluesky);

        self::assertNull(new BlueskyCanonicalKeyRule()->findKey($entry));
    }

    public function testEntryOfAnotherNetworkHasNoKey(): void
    {
        self::assertNull(new BlueskyCanonicalKeyRule()->findKey($this->entry(self::AT_URI, SocialNetwork::Mastodon)));
    }

    public function testArticleFeedHasNoKey(): void
    {
        self::assertNull(new BlueskyCanonicalKeyRule()->findKey($this->entry(self::AT_URI, null)));
    }

    private function entry(string $guid, ?SocialNetwork $network): Entry
    {
        $feed = new Feed('https://example.com/feed');
        $feed->classifyAs($network);
        $now = new \DateTimeImmutable('2026-01-01');

        return new Entry($feed, $guid, null, 'Title', $now, $now);
    }
}
