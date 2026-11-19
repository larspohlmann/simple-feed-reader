<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\LinkCard\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Enum\ShareKind;
use App\Service\Content\LinkCard\Support\ReusableShareUrls;
use App\Service\Social\Share\Model\ShareFetchPlanModel;
use PHPUnit\Framework\TestCase;

final class ReusableShareUrlsTest extends TestCase
{
    public function testEveryLinkOfAPostIsReusable(): void
    {
        $plan = new ShareFetchPlanModel(['https://a.example/1', 'https://b.example/2']);

        self::assertSame($plan->urls, ReusableShareUrls::filterUrls(self::post('<p>Two links</p>'), $plan));
    }

    public function testThePostsQuoteLineTargetIsNot(): void
    {
        $post = self::post('<p>RE: <a href="https://q.example/@a/1?utm_source=x">q</a></p>');
        $plan = new ShareFetchPlanModel(['https://q.example/@a/1', 'https://b.example/2']);

        self::assertSame(['https://b.example/2'], ReusableShareUrls::filterUrls($post, $plan));
    }

    public function testATitledSharesTargetIsNot(): void
    {
        $feed = new Feed('https://feeds.example/feed');
        $feed->setSiteUrl('https://daringfireball.net/');
        $now = new \DateTimeImmutable('2026-11-15 10:00:00');
        $share = new Entry($feed, 'tag:daringfireball.net,2026:/linked//1', 'https://x.com/a/1', 'A story', $now, $now);
        $share->setHeadline(Headline::fromFeed('A story'));
        $share->setShareKind(ShareKind::Link);

        self::assertSame([], ReusableShareUrls::filterUrls($share, new ShareFetchPlanModel(['https://x.com/a/1'])));
    }

    private static function post(string $html): Entry
    {
        $now = new \DateTimeImmutable('2026-11-15 10:00:00');
        $entry = new Entry(new Feed('https://m.example/@a.rss'), 'https://m.example/@a/1', null, 'Post', $now, $now);
        $entry->setHeadline(Headline::derived('Post'));
        $entry->recordText($entry->getText()->withContentHtml($html));

        return $entry;
    }
}
