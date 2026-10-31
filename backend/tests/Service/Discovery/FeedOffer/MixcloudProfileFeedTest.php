<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\FeedOffer;

use App\Enum\SourceFormat;
use App\Service\Discovery\FeedOffer\MixcloudProfileFeed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MixcloudProfileFeedTest extends TestCase
{
    private const string PROFILE_URL = 'https://www.mixcloud.com/johndigweed/';

    /** The profile page's head on 2026-10-31, trimmed to what an offer may read. */
    private const string PROFILE_PAGE = /** @lang HTML */ <<<'HTML'
        <html lang="en"><head><title>John Digweed | Mixcloud</title>
        <link rel="alternate" type="application/json+oembed" title="John Digweed"
              href="https://app.mixcloud.com/oembed/?url=https%3A%2F%2Fwww.mixcloud.com%2Fjohndigweed%2F"/>
        </head><body></body></html>
        HTML;

    public function testOffersTheCloudcastsOfTheProfileUser(): void
    {
        $candidate = (new MixcloudProfileFeed())->offer(self::PROFILE_PAGE, self::PROFILE_URL);

        self::assertNotNull($candidate);
        self::assertSame('https://api.mixcloud.com/johndigweed/cloudcasts/', $candidate->url);
        self::assertSame('John Digweed | Mixcloud', $candidate->title);
        self::assertSame(SourceFormat::MIXCLOUD_API, $candidate->format);
    }

    public function testAPageWithoutATitleIsStillOffered(): void
    {
        $candidate = (new MixcloudProfileFeed())->offer('', self::PROFILE_URL);

        self::assertNotNull($candidate);
        self::assertNull($candidate->title);
    }

    /** @return iterable<string, array{string}> */
    public static function pagesThatOfferNothing(): iterable
    {
        yield 'a profile tab' => ['https://www.mixcloud.com/johndigweed/favorites/'];
        yield 'the discover section' => ['https://www.mixcloud.com/discover/'];
        yield 'the upload page' => ['https://www.mixcloud.com/upload/'];
        yield 'the select page' => ['https://www.mixcloud.com/select/'];
        yield 'the home page' => ['https://www.mixcloud.com/'];
        yield 'another host' => ['https://soundcloud.com/johndigweed'];
        yield 'a look-alike host' => ['https://www.mixcloud.com.evil.test/johndigweed/'];
    }

    #[DataProvider('pagesThatOfferNothing')]
    public function testOffersNothingForAPageThatNamesNoUser(string $pageUrl): void
    {
        self::assertNull((new MixcloudProfileFeed())->offer(self::PROFILE_PAGE, $pageUrl));
    }

    /** @return iterable<string, array{string}> */
    public static function addressesNamingTheUser(): iterable
    {
        yield 'a show page' => ['https://www.mixcloud.com/johndigweed/transitions-1154/'];
        yield 'the cloudcasts API' => ['https://api.mixcloud.com/johndigweed/cloudcasts/'];
        yield 'the user API' => ['https://api.mixcloud.com/johndigweed/'];
    }

    #[DataProvider('addressesNamingTheUser')]
    public function testOffersTheUsersCloudcastsUntitledForAShowPageOrAnApiAddress(string $pageUrl): void
    {
        $candidate = (new MixcloudProfileFeed())->offer(self::PROFILE_PAGE, $pageUrl);

        self::assertNotNull($candidate);
        self::assertSame('https://api.mixcloud.com/johndigweed/cloudcasts/', $candidate->url);
        self::assertNull($candidate->title);
        self::assertSame(SourceFormat::MIXCLOUD_API, $candidate->format);
    }
}
