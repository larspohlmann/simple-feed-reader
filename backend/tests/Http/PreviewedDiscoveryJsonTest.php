<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Enum\SourceFormat;
use App\Http\FeedPreviewJson;
use App\Http\PreviewedDiscoveryJson;
use App\Service\Sourcing\Discovery\Model\FeedCandidateModel;
use App\Service\Sourcing\Discovery\Model\NoPreviewReason;
use App\Service\Sourcing\Discovery\Model\ScrapeFailureReason;
use App\Service\Sourcing\Preview\Model\FeedPreviewModel;
use App\Service\Sourcing\Preview\Model\PreviewedCandidateModel;
use App\Service\Sourcing\Preview\Model\PreviewedDiscoveryModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PreviewedDiscoveryJsonTest extends TestCase
{
    public function testACandidateWithoutAPreviewCarriesOnlyItsAddress(): void
    {
        $found = new PreviewedDiscoveryModel([
            new PreviewedCandidateModel(new FeedCandidateModel('https://example.com/rss', 'Example', 'rss')),
            new PreviewedCandidateModel(new FeedCandidateModel('https://example.com/atom', null, 'atom')),
        ]);

        self::assertSame(
            ['candidates' => [
                ['url' => 'https://example.com/rss', 'title' => 'Example', 'format' => 'rss', 'label' => 'RSS'],
                ['url' => 'https://example.com/atom', 'title' => null, 'format' => 'atom', 'label' => 'Atom'],
            ]],
            PreviewedDiscoveryJson::of($found),
        );
    }

    public function testAPreviewedCandidateCarriesThePreviewEndpointsShape(): void
    {
        $preview = new FeedPreviewModel('Example', 0, 'title-only', false, []);
        $found = new PreviewedDiscoveryModel([new PreviewedCandidateModel(
            new FeedCandidateModel('https://example.com/feed.xml', 'Example', 'rss'),
            $preview,
        )]);

        self::assertSame(
            ['candidates' => [[
                'url' => 'https://example.com/feed.xml',
                'title' => 'Example',
                'format' => 'rss',
                'label' => 'RSS',
                'preview' => FeedPreviewJson::one($preview)['feed'],
            ]]],
            PreviewedDiscoveryJson::of($found),
        );
    }

    public function testCarriesTheScrapeFailureReasonWhenThereIsOne(): void
    {
        self::assertSame(
            ['candidates' => [], 'scrapeFailureReason' => 'blocked'],
            PreviewedDiscoveryJson::of(new PreviewedDiscoveryModel([], ScrapeFailureReason::Blocked)),
        );
    }

    public function testACandidateNoPreviewCanBeFetchedForSaysWhy(): void
    {
        $found = new PreviewedDiscoveryModel([new PreviewedCandidateModel(new FeedCandidateModel(
            'https://bsky.app/profile/rusty-17.bsky.social',
            'Rusty',
            SourceFormat::BLUESKY_API,
            NoPreviewReason::HiddenProfile,
        ))]);

        self::assertSame(
            ['candidates' => [[
                'url' => 'https://bsky.app/profile/rusty-17.bsky.social',
                'title' => 'Rusty',
                'format' => 'bluesky-api',
                'label' => 'Bluesky',
                'noPreviewReason' => 'hidden_profile',
            ]]],
            PreviewedDiscoveryJson::of($found),
        );
    }

    public function testACandidateThatNeedsAConnectedAccountSaysSo(): void
    {
        $found = new PreviewedDiscoveryModel([new PreviewedCandidateModel(new FeedCandidateModel(
            'https://bsky.app/profile/rusty-17.bsky.social',
            'Rusty',
            SourceFormat::BLUESKY_API,
            NoPreviewReason::ConnectBlueskyAccount,
        ))]);

        $candidates = PreviewedDiscoveryJson::of($found)['candidates'] ?? null;
        self::assertIsArray($candidates);
        self::assertIsArray($candidates[0]);
        self::assertSame('connect_bluesky_account', $candidates[0]['noPreviewReason']);
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function formats(): iterable
    {
        yield 'rss' => ['rss', 'RSS', null];
        yield 'atom' => ['atom', 'Atom', null];
        yield 'guessed' => [FeedCandidateModel::GUESSED_FORMAT, 'Feed', null];
        yield 'wordpress' => [SourceFormat::WP_JSON, 'WordPress', SourceFormat::WP_JSON];
        yield 'mixcloud' => [SourceFormat::MIXCLOUD_API, 'Mixcloud', SourceFormat::MIXCLOUD_API];
        yield 'scraped' => [SourceFormat::SCRAPED, 'Scraped', SourceFormat::SCRAPED];
        yield 'bluesky' => [SourceFormat::BLUESKY_API, 'Bluesky', null];
        yield 'unknown' => ['podcast', 'Podcast', null];
        yield 'empty' => ['', 'Feed', null];
    }

    #[DataProvider('formats')]
    public function testACandidateCarriesItsLabelAndTheFormatSubscribeMustSend(
        string $format,
        string $label,
        ?string $storedFormat,
    ): void {
        $json = PreviewedDiscoveryJson::of(new PreviewedDiscoveryModel([
            new PreviewedCandidateModel(new FeedCandidateModel('https://example.com/x', null, $format)),
        ]));

        $candidates = $json['candidates'] ?? null;
        self::assertIsArray($candidates);
        self::assertIsArray($candidates[0]);
        self::assertSame($label, $candidates[0]['label']);
        self::assertSame($storedFormat, $candidates[0]['storedFormat'] ?? null);
    }
}
