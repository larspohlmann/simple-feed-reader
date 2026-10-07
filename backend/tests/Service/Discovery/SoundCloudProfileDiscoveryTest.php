<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Service\Discovery\Model\ScrapeFallback;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Proves FeedDiscovery lists the profile's feed; the deep-link edge cases are SoundCloudProfileFeedTest's. */
final class SoundCloudProfileDiscoveryTest extends KernelTestCase
{
    use BuildsFeedDiscovery;

    private const string PROFILE_URL = 'https://soundcloud.com/mobitex';

    public function testAProfileOffersItsRssFeedBeforeAnyGuessOrScrape(): void
    {
        $fetcher = $this->fetcherReturning(self::PROFILE_URL, self::PROFILE_URL, $this->fixture('profile.html'));

        $result = $this->discovery($fetcher)->discover(self::PROFILE_URL, ScrapeFallback::Enabled);

        self::assertNull($result->feed);
        self::assertCount(1, $result->candidates);
        self::assertSame(
            'https://feeds.soundcloud.com/users/soundcloud:users:19838136/sounds.rss',
            $result->candidates[0]->url,
        );
        self::assertSame('rss', $result->candidates[0]->format);
        self::assertSame([self::PROFILE_URL], $fetcher->fetchedUrls);
    }

    public function testATrackPageIsLeftToTheUsualFallbacks(): void
    {
        $trackUrl = 'https://soundcloud.com/forss/flickermood';
        $fetcher = $this->fetcherReturning($trackUrl, $trackUrl, $this->fixture('track.html'));

        $result = $this->discovery($fetcher)->discover($trackUrl, ScrapeFallback::Disabled);

        self::assertSame([], $result->candidates);
    }

    private function fixture(string $name): string
    {
        $html = file_get_contents(__DIR__ . '/../../Fixtures/soundcloud/' . $name);
        self::assertIsString($html);

        return $html;
    }
}
