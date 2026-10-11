<?php

declare(strict_types=1);

namespace App\Tests\Service\Share\Support;

use App\Entity\Discussion;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\CommentsLoad;
use App\Service\Share\Support\TitledShareTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TitledShareTargetTest extends TestCase
{
    private static function entry(string $siteUrl, string $guid, ?string $url, ?string $discussionUrl = null): Entry
    {
        $feed = new Feed('https://feeds.example/feed');
        $feed->setSiteUrl($siteUrl);
        $now = new \DateTimeImmutable('2026-10-11 09:00:00');
        $entry = new Entry($feed, $guid, $url, 'Title', $now, $now);
        if ($discussionUrl !== null) {
            $entry->setDiscussion(Discussion::of($discussionUrl, null, CommentsLoad::Manual));
        }

        return $entry;
    }

    /** @return iterable<string, array{Entry, ?string}> */
    public static function entries(): iterable
    {
        yield 'Hacker News: home is the discussion' => [
            self::entry(
                'https://news.ycombinator.com/',
                'https://news.ycombinator.com/item?id=5',
                'https://ghuntley.com/unikernels/',
                'https://news.ycombinator.com/item?id=5'
            ),
            'https://ghuntley.com/unikernels/',
        ];
        yield 'Daring Fireball: home is a tag GUID' => [
            self::entry(
                'https://daringfireball.net/',
                'tag:daringfireball.net,2026:/linked//6.42',
                'https://x.com/bzamayo/status/1'
            ),
            'https://x.com/bzamayo/status/1',
        ];
        yield 'POLITICO Europe: home is a URL GUID' => [
            self::entry(
                'https://www.politico.eu/',
                'https://www.politico.eu/?p=8891306',
                'https://www.politico.com/news/a'
            ),
            'https://www.politico.com/news/a',
        ];
        yield 'ZDF: an opaque GUID is no home' => [
            self::entry('https://www.zdfheute.de/', 'SCMS_02561894', 'https://www.3sat.de/kultur/a'),
            null,
        ];
        yield 'an article on its own site' => [
            self::entry('https://www.bbc.co.uk/news', 'https://www.bbc.co.uk/news/a', 'https://www.bbc.co.uk/news/a'),
            null,
        ];
        yield 'a comments service is one home, the site another' => [
            self::entry(
                'https://blog.example/',
                'https://blog.example/posts/1',
                'https://elsewhere.example/a',
                'https://comments.example/thread/1'
            ),
            'https://elsewhere.example/a',
        ];
        yield 'Ask HN: the target is the home' => [
            self::entry(
                'https://news.ycombinator.com/',
                'https://news.ycombinator.com/item?id=6',
                'https://news.ycombinator.com/item?id=6',
                'https://news.ycombinator.com/item?id=6'
            ),
            null,
        ];
        yield 'no url' => [
            self::entry('https://news.ycombinator.com/', 'https://news.ycombinator.com/item?id=7', null),
            null,
        ];
    }

    #[DataProvider('entries')]
    public function testFindsTheTarget(Entry $entry, ?string $expected): void
    {
        self::assertSame($expected, TitledShareTarget::of($entry));
    }

    public function testAFeedWithoutASiteHasNoTitledShares(): void
    {
        $now = new \DateTimeImmutable();
        $entry = new Entry(
            new Feed('https://feeds.example/feed'),
            'https://feeds.example/a',
            'https://other.example/a',
            'T',
            $now,
            $now,
        );

        self::assertNull(TitledShareTarget::of($entry));
    }
}
