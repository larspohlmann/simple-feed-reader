<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Enum\SourceFormat;
use App\Http\FeedPreviewJson;
use App\Http\PreviewedDiscoveryJson;
use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Discovery\Model\NoPreviewReason;
use App\Service\Discovery\Model\ScrapeFailureReason;
use App\Service\Preview\Model\FeedPreviewModel;
use App\Service\Preview\Model\PreviewedCandidateModel;
use App\Service\Preview\Model\PreviewedDiscoveryModel;
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
                ['url' => 'https://example.com/rss', 'title' => 'Example', 'format' => 'rss'],
                ['url' => 'https://example.com/atom', 'title' => null, 'format' => 'atom'],
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
}
