<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\FeedOffer;

use App\Service\Discovery\FeedOffer\SoundCloudProfileFeed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SoundCloudProfileFeedTest extends TestCase
{
    private const string PROFILE_URL = 'https://soundcloud.com/mobitex';

    public function testOffersTheRssFeedOfTheUserTheProfileDeepLinks(): void
    {
        $candidate = (new SoundCloudProfileFeed())->offer($this->fixture('profile.html'), self::PROFILE_URL);

        self::assertNotNull($candidate);
        self::assertSame(
            'https://feeds.soundcloud.com/users/soundcloud:users:19838136/sounds.rss',
            $candidate->url,
        );
        self::assertSame('Mobitex (PCT rec)', $candidate->title);
        self::assertSame('rss', $candidate->format);
    }

    public function testATrackPageOffersNothing(): void
    {
        $trackUrl = 'https://soundcloud.com/forss/flickermood';

        self::assertNull((new SoundCloudProfileFeed())->offer($this->fixture('track.html'), $trackUrl));
    }

    /** @return iterable<string, array{string}> */
    public static function pagesWithoutAUserDeepLink(): iterable
    {
        yield 'no deep link' => ['<html lang="en"><head><title>Discover</title></head></html>'];
        yield 'another app scheme' => [self::deepLinkPage('spotify://users:42')];
        yield 'id with a suffix' => [self::deepLinkPage('soundcloud://users:42/likes')];
        yield 'id with a prefix' => [self::deepLinkPage('xsoundcloud://users:42')];
        yield 'no id' => [self::deepLinkPage('soundcloud://users:')];
        yield 'a non-numeric id' => [self::deepLinkPage('soundcloud://users:abc')];
        yield 'not html' => ['{"kind":"user","id":42}'];
    }

    #[DataProvider('pagesWithoutAUserDeepLink')]
    public function testAPageWithoutAUserDeepLinkOffersNothing(string $body): void
    {
        self::assertNull((new SoundCloudProfileFeed())->offer($body, self::PROFILE_URL));
    }

    public function testAProfileWithoutAnOgTitleIsOfferedUntitled(): void
    {
        $body = '<html lang="en"><head><title>Stream X music</title>'
            . '<meta property="al:ios:url" content="soundcloud://users:42"></head></html>';

        $candidate = (new SoundCloudProfileFeed())->offer($body, self::PROFILE_URL);

        self::assertNotNull($candidate);
        self::assertSame('https://feeds.soundcloud.com/users/soundcloud:users:42/sounds.rss', $candidate->url);
        self::assertNull($candidate->title);
    }

    public function testTheTitleIsWhitespaceNormalised(): void
    {
        $body = '<html lang="en"><head><meta property="og:title" content="  Mobitex   (PCT rec) ">'
            . '<meta property="al:ios:url" content=" soundcloud://users:42 "></head></html>';

        $candidate = (new SoundCloudProfileFeed())->offer($body, self::PROFILE_URL);

        self::assertNotNull($candidate);
        self::assertSame('Mobitex (PCT rec)', $candidate->title);
        self::assertSame('https://feeds.soundcloud.com/users/soundcloud:users:42/sounds.rss', $candidate->url);
    }

    private static function deepLinkPage(string $deepLink): string
    {
        return '<html lang="en"><head><meta property="al:ios:url" content="' . $deepLink . '"></head></html>';
    }

    private function fixture(string $name): string
    {
        $html = file_get_contents(__DIR__ . '/../../../Fixtures/soundcloud/' . $name);
        self::assertIsString($html);

        return $html;
    }
}
