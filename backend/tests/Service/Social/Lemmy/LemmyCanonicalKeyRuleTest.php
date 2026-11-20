<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy;

use App\Entity\Discussion;
use App\Enum\CanonicalItemKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Lemmy\LemmyCanonicalKeyRule;
use App\Service\Social\Lemmy\Support\LemmyProfileFeed;
use App\Tests\Support\CanonicalKeyRuleEntries;
use PHPUnit\Framework\TestCase;

final class LemmyCanonicalKeyRuleTest extends TestCase
{
    public function testApIdAndHomeRssPageShareOneKey(): void
    {
        $rule = new LemmyCanonicalKeyRule();

        $api = $rule->findKey(
            CanonicalKeyRuleEntries::buildDiscussionEntry('https://lemmy.world/post/54626839', SocialNetwork::Lemmy),
        );
        $rss = $rule->findKey(
            CanonicalKeyRuleEntries::buildEntry(
                'https://lemmy.world/post/54626839',
                'https://example.com/story',
                SocialNetwork::Lemmy,
            ),
        );

        self::assertSame(CanonicalItemKind::Lemmy, $api?->kind);
        self::assertSame('https://lemmy.world/post/54626839', $api->key);
        self::assertSame($api->key, $rss?->key);
    }

    public function testForeignRssPageIsItsOwnKey(): void
    {
        $rule = new LemmyCanonicalKeyRule();

        $foreign = $rule->findKey(
            CanonicalKeyRuleEntries::buildEntry(
                'https://lemmy.ml/post/5',
                'https://example.com/story',
                SocialNetwork::Lemmy,
            ),
        );
        $api = $rule->findKey(
            CanonicalKeyRuleEntries::buildDiscussionEntry('https://lemmy.world/post/9', SocialNetwork::Lemmy),
        );

        self::assertNotSame($api?->key, $foreign?->key);
    }

    public function testCommunityRssFeedCarriesTheLemmyNetwork(): void
    {
        self::assertNotNull(LemmyProfileFeed::parseIdentity('https://lemmy.world/feeds/c/technology.xml'));
    }

    public function testADiscussionThreadOnAFeedWithoutNetworkStillHasAKey(): void
    {
        $key = new LemmyCanonicalKeyRule()->findKey(
            CanonicalKeyRuleEntries::buildDiscussionEntry('https://lemmy.world/post/7', null),
        );

        self::assertSame('https://lemmy.world/post/7', $key?->key);
    }

    public function testSavedCopyGuidKeysAsItsOriginal(): void
    {
        $entry = CanonicalKeyRuleEntries::buildEntry(
            'https://lemmy.world/post/7#42',
            'https://example.com/story',
            SocialNetwork::Lemmy,
        );
        $key = new LemmyCanonicalKeyRule()->findKey($entry);

        self::assertSame('https://lemmy.world/post/7', $key?->key);
    }

    public function testTheGuidWinsOverTheDiscussionAddress(): void
    {
        $entry = CanonicalKeyRuleEntries::buildEntry(
            'https://lemmy.world/post/7',
            'https://example.com/story',
            SocialNetwork::Lemmy,
        );
        $entry->setDiscussion(Discussion::platformThread('https://lemmy.ml/post/9'));

        self::assertSame('https://lemmy.world/post/7', new LemmyCanonicalKeyRule()->findKey($entry)?->key);
    }

    public function testEntryOfAnotherNetworkHasNoKey(): void
    {
        $entry = CanonicalKeyRuleEntries::buildEntry(
            'https://lemmy.world/post/7',
            'https://example.com/story',
            SocialNetwork::Mastodon,
        );

        self::assertNull(new LemmyCanonicalKeyRule()->findKey($entry));
    }

    public function testArticleFeedHasNoKey(): void
    {
        self::assertNull(
            new LemmyCanonicalKeyRule()->findKey(
                CanonicalKeyRuleEntries::buildEntry('https://lemmy.world/post/7', 'https://example.com/story', null),
            ),
        );
    }
}
