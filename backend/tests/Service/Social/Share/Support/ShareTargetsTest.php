<?php

declare(strict_types=1);

namespace App\Tests\Service\Share\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Enum\ShareKind;
use App\Service\Share\Support\ShareTargets;
use PHPUnit\Framework\TestCase;

final class ShareTargetsTest extends TestCase
{
    private const string BODY_LINK = 'https://other.example/x';
    private const string TARGET = 'https://x.com/a/1';

    private static function entry(string $siteUrl, string $guid, ?string $url): Entry
    {
        $feed = new Feed('https://feeds.example/feed');
        $feed->setSiteUrl($siteUrl);
        $now = new \DateTimeImmutable();
        $entry = new Entry($feed, $guid, $url, 'A real headline', $now, $now);
        $entry->setContentHtml('<p>' . self::BODY_LINK . '</p>');
        $entry->setShareKind(ShareKind::Link);

        return $entry;
    }

    private static function titledShare(): Entry
    {
        return self::entry('https://daringfireball.net/', 'tag:daringfireball.net,2026:/linked//1', self::TARGET);
    }

    public function testATitledShareTargetsTheEntrysOwnUrl(): void
    {
        $entry = self::titledShare();

        self::assertSame([self::TARGET], ShareTargets::uncardedOf($entry, []));
        self::assertSame([], ShareTargets::uncardedOf($entry, [self::TARGET]));
    }

    public function testAPostTargetsTheLinksItsBodyShares(): void
    {
        $entry = self::titledShare();
        $entry->setHeadline(Headline::derived('A real headline'));
        $entry->setContentHtml('<p><a href="https://a.example/1">https://a.example/1</a> '
            . '<a href="https://b.example/2">https://b.example/2</a></p>');

        self::assertSame(['https://b.example/2'], ShareTargets::uncardedOf($entry, ['https://a.example/1']));
    }

    public function testATitledBlueskyPostAwaitingItsEmbedTakesItsBodyLinksNotItsOwnUrl(): void
    {
        $entry = self::entry(
            'https://bsky.app/profile/someone',
            'at://did:plc:test/app.bsky.feed.post/3abc',
            'https://bsky.app/profile/someone/post/3abc',
        );

        self::assertSame([self::BODY_LINK], ShareTargets::uncardedOf($entry, []));
    }
}
