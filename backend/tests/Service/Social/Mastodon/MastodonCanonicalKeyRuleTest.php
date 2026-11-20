<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Mastodon;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\CanonicalItemKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Mastodon\MastodonCanonicalKeyRule;
use PHPUnit\Framework\TestCase;

final class MastodonCanonicalKeyRuleTest extends TestCase
{
    private const string KEY = 'https://mastodon.social/users/alice/statuses/123';

    public function testTimelineAndRssFormsShareOneKey(): void
    {
        $rule = new MastodonCanonicalKeyRule();

        $timeline = $rule->findKey($this->entry(self::KEY, null, SocialNetwork::Mastodon));
        $rssByGuid = $rule->findKey($this->entry('https://mastodon.social/@alice/123', null, SocialNetwork::Mastodon));
        $rssByUrl = $rule->findKey(
            $this->entry('tag:mastodon.social,2026:123', 'https://mastodon.social/@alice/123', SocialNetwork::Mastodon),
        );

        self::assertSame(CanonicalItemKind::Mastodon, $timeline?->kind);
        self::assertSame(self::KEY, $timeline->key);
        self::assertSame(self::KEY, $rssByGuid?->key);
        self::assertSame(self::KEY, $rssByUrl?->key);
    }

    public function testSavedCopyGuidKeysAsItsOriginal(): void
    {
        $key = new MastodonCanonicalKeyRule()->findKey($this->entry(self::KEY . '#42', null, SocialNetwork::Mastodon));

        self::assertSame(self::KEY, $key?->key);
    }

    public function testEntryOfAnotherNetworkHasNoKey(): void
    {
        self::assertNull(new MastodonCanonicalKeyRule()->findKey($this->entry(self::KEY, null, SocialNetwork::Lemmy)));
    }

    public function testArticleFeedHasNoKey(): void
    {
        self::assertNull(new MastodonCanonicalKeyRule()->findKey($this->entry(self::KEY, null, null)));
    }

    private function entry(string $guid, ?string $url, ?SocialNetwork $network): Entry
    {
        $feed = new Feed('https://example.com/feed');
        $feed->classifyAs($network);
        $now = new \DateTimeImmutable('2026-01-01');

        return new Entry($feed, $guid, $url, 'Title', $now, $now);
    }
}
