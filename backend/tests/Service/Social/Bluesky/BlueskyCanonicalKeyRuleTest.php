<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Bluesky;

use App\Enum\CanonicalItemKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\BlueskyCanonicalKeyRule;
use App\Tests\Support\CanonicalKeyRuleEntries;
use PHPUnit\Framework\TestCase;

final class BlueskyCanonicalKeyRuleTest extends TestCase
{
    private const string AT_URI = 'at://did:plc:abc/app.bsky.feed.post/3k';

    public function testAtUriIsTheKey(): void
    {
        $key = new BlueskyCanonicalKeyRule()->findKey(
            CanonicalKeyRuleEntries::buildEntry(self::AT_URI, null, SocialNetwork::Bluesky),
        );

        self::assertSame(CanonicalItemKind::Bluesky, $key?->kind);
        self::assertSame(self::AT_URI, $key->key);
    }

    public function testSavedCopyGuidKeysAsItsOriginal(): void
    {
        $key = new BlueskyCanonicalKeyRule()->findKey(
            CanonicalKeyRuleEntries::buildEntry(self::AT_URI . '#42', null, SocialNetwork::Bluesky),
        );

        self::assertSame(self::AT_URI, $key?->key);
    }

    public function testAGuidThatIsNoAtUriHasNoKey(): void
    {
        $entry = CanonicalKeyRuleEntries::buildEntry('https://example.com/a', null, SocialNetwork::Bluesky);

        self::assertNull(new BlueskyCanonicalKeyRule()->findKey($entry));
    }

    public function testEntryOfAnotherNetworkHasNoKey(): void
    {
        self::assertNull(
            new BlueskyCanonicalKeyRule()->findKey(
                CanonicalKeyRuleEntries::buildEntry(self::AT_URI, null, SocialNetwork::Mastodon),
            ),
        );
    }

    public function testArticleFeedHasNoKey(): void
    {
        self::assertNull(
            new BlueskyCanonicalKeyRule()->findKey(CanonicalKeyRuleEntries::buildEntry(self::AT_URI, null, null)),
        );
    }
}
