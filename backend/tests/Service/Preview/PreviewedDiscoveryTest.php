<?php

declare(strict_types=1);

namespace App\Tests\Service\Preview;

use App\Entity\Headline;
use App\Entity\ReadingSettings;
use App\Repository\ReadingSettingsRepository;
use App\Service\Discovery\FeedDiscovery\FeedDiscoveryInterface;
use App\Service\Discovery\Model\DiscoveredFeedModel;
use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Discovery\Model\FeedDiscoveryResultModel;
use App\Service\Discovery\Model\ScrapeFailureReason;
use App\Service\Discovery\Model\ScrapeFallback;
use App\Service\Discovery\ScrapeFallbackPolicy;
use App\Service\Parser\Model\FeedSyntax;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Preview\Factory\FeedPreviewFactory;
use App\Service\Preview\Model\PreviewedCandidateModel;
use App\Service\Preview\PreviewedDiscovery;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingFeedDiscovery;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StoredUserSettings;

final class PreviewedDiscoveryTest extends DbTestCase
{
    use SeedsUsers;

    private function service(FeedDiscoveryInterface $discovery): PreviewedDiscovery
    {
        $readingSettings = $this->entityManager->getRepository(ReadingSettings::class);
        self::assertInstanceOf(ReadingSettingsRepository::class, $readingSettings);

        return new PreviewedDiscovery($discovery, new ScrapeFallbackPolicy($readingSettings), new FeedPreviewFactory());
    }

    public function testADirectFeedIsOneCandidateCarryingThePreviewOfTheDocumentDiscoveryRead(): void
    {
        $entry = new ParsedEntryModel(
            guid: 'https://example.com/1',
            url: 'https://example.com/1',
            headline: Headline::fromFeed('First post'),
            author: null,
            summary: 'Teaser.',
            contentHtml: null,
            publishedAt: null,
        );
        $discovery = new RecordingFeedDiscovery(FeedDiscoveryResultModel::directFeed(new DiscoveredFeedModel(
            'https://example.com/feed.xml',
            new ParsedFeedModel('Example', null, null, null, [$entry], syntax: FeedSyntax::Atom),
        )));

        $found = $this->service($discovery)->discover($this->user('direct@example.test'), 'https://example.com/feed');

        self::assertCount(1, $found->candidates);
        self::assertEquals(
            new FeedCandidateModel('https://example.com/feed.xml', 'Example', 'atom'),
            $found->candidates[0]->candidate,
        );
        $preview = $found->candidates[0]->preview;
        self::assertNotNull($preview);
        self::assertSame(1, $preview->itemCount);
        self::assertSame('First post', $preview->items[0]->title);
        self::assertNull($found->scrapeFailureReason);
        self::assertSame(['https://example.com/feed'], $discovery->urls);
    }

    public function testAPagesCandidatesComeWithoutPreviews(): void
    {
        $candidate = new FeedCandidateModel('https://example.com/rss.xml', 'Main', 'rss');
        $discovery = new RecordingFeedDiscovery(FeedDiscoveryResultModel::candidates([$candidate]));

        $found = $this->service($discovery)->discover($this->user('page@example.test'), 'https://example.com/');

        self::assertEquals([new PreviewedCandidateModel($candidate)], $found->candidates);
        self::assertNull($found->scrapeFailureReason);
    }

    public function testAScrapeFailureCarriesItsReason(): void
    {
        $discovery = new RecordingFeedDiscovery(FeedDiscoveryResultModel::scrapeFailed(ScrapeFailureReason::Blocked));

        $found = $this->service($discovery)->discover($this->user('blocked@example.test'), 'https://example.com/');

        self::assertSame([], $found->candidates);
        self::assertSame(ScrapeFailureReason::Blocked, $found->scrapeFailureReason);
    }

    public function testDiscoveryFollowsTheAccountsScrapePreference(): void
    {
        $off = new RecordingFeedDiscovery(FeedDiscoveryResultModel::candidates([]));
        $this->service($off)->discover($this->user('scrape-off@example.test'), 'https://example.com/');

        $user = $this->user('scrape-on@example.test');
        StoredUserSettings::reading($this->entityManager, $user)->setScrapeFallbackEnabled(true);
        $this->entityManager->flush();
        $on = new RecordingFeedDiscovery(FeedDiscoveryResultModel::candidates([]));
        $this->service($on)->discover($user, 'https://example.com/');

        self::assertSame([ScrapeFallback::Disabled], $off->fallbacks);
        self::assertSame([ScrapeFallback::Enabled], $on->fallbacks);
    }
}
