<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Share\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Enum\ShareKind;
use App\Service\Social\Share\Support\ShareTargets;
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
        $entry->recordText($entry->getText()->withContentHtml('<p>' . self::BODY_LINK . '</p>'));
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

        self::assertSame([self::TARGET], ShareTargets::buildPlan($entry, [])->urls);
        self::assertSame([], ShareTargets::buildPlan($entry, [self::TARGET])->urls);
    }

    public function testATitledShareStillCarryingATrackerMatchesItsCleanedCard(): void
    {
        $entry = self::entry(
            'https://daringfireball.net/',
            'tag:daringfireball.net,2026:/linked//2',
            self::TARGET . '?utm_source=x',
        );

        self::assertSame([], ShareTargets::buildPlan($entry, [self::TARGET])->urls);
    }

    public function testATitledShareMatchesACardKeptUnderATrackedForm(): void
    {
        self::assertSame([], ShareTargets::buildPlan(self::titledShare(), [self::TARGET . '?utm_source=x'])->urls);
    }

    public function testAPostTargetsTheLinksItsBodyShares(): void
    {
        $entry = self::titledShare();
        $entry->setHeadline(Headline::derived('A real headline'));
        $entry->recordText($entry->getText()->withContentHtml(
            '<p><a href="https://a.example/1">https://a.example/1</a> '
            . '<a href="https://b.example/2">https://b.example/2</a></p>',
        ));

        self::assertSame(['https://b.example/2'], ShareTargets::buildPlan($entry, ['https://a.example/1'])->urls);
    }

    public function testAPostsTrackedLinkNamesItsPostedForm(): void
    {
        $entry = self::trackedPost();

        $plan = ShareTargets::buildPlan($entry, []);

        self::assertSame(['https://a.example/1', 'https://b.example/2'], $plan->urls);
        self::assertSame(['https://a.example/1' => 'https://a.example/1?cmp=rss'], $plan->postedForms);
        self::assertSame([], ShareTargets::buildPlan($entry, ['https://a.example/1'])->postedForms);
    }

    public function testACardKeptUnderItsPostedFormCardsItsLink(): void
    {
        self::assertSame(
            ['https://b.example/2'],
            ShareTargets::buildPlan(self::trackedPost(), ['https://a.example/1?cmp=rss'])->urls,
        );
    }

    public function testATitledShareNamesNoPostedForm(): void
    {
        self::assertSame([], ShareTargets::buildPlan(self::titledShare(), [])->postedForms);
    }

    private static function trackedPost(): Entry
    {
        $entry = self::titledShare();
        $entry->setHeadline(Headline::derived('A real headline'));
        $entry->recordText($entry->getText()->withContentHtml(
            '<p><a href="https://a.example/1?cmp=rss">https://a.example/1?cmp=rss</a> '
            . '<a href="https://b.example/2">https://b.example/2</a></p>',
        ));

        return $entry;
    }

    public function testATitledBlueskyPostAwaitingItsEmbedTakesItsBodyLinksNotItsOwnUrl(): void
    {
        $entry = self::entry(
            'https://bsky.app/profile/someone',
            'at://did:plc:test/app.bsky.feed.post/3abc',
            'https://bsky.app/profile/someone/post/3abc',
        );

        self::assertSame([self::BODY_LINK], ShareTargets::buildPlan($entry, [])->urls);
    }
}
