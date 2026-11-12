<?php

declare(strict_types=1);

namespace App\Tests\Service\Preview;

use App\Entity\Headline;
use App\Entity\ReadingSettings;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Repository\ReadingSettingsRepository;
use App\Service\Discovery\AccountOnlyFeed\AccountOnlyFeedInterface;
use App\Service\Discovery\FeedDiscovery\FeedDiscoveryInterface;
use App\Service\Discovery\Model\DiscoveredFeedModel;
use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Discovery\Model\FeedDiscoveryResultModel;
use App\Service\Discovery\Model\NoPreviewReason;
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
use App\Tests\Support\ThrottledFeedDiscovery;

final class PreviewedDiscoveryTest extends DbTestCase
{
    use SeedsUsers;

    /** @param list<AccountOnlyFeedInterface> $accountOnlyFeeds */
    private function service(FeedDiscoveryInterface $discovery, array $accountOnlyFeeds = []): PreviewedDiscovery
    {
        $readingSettings = $this->entityManager->getRepository(ReadingSettings::class);
        self::assertInstanceOf(ReadingSettingsRepository::class, $readingSettings);

        return new PreviewedDiscovery(
            $discovery,
            new ScrapeFallbackPolicy($readingSettings),
            new FeedPreviewFactory(),
            $accountOnlyFeeds,
        );
    }

    private function offering(?FeedCandidateModel $candidate): AccountOnlyFeedInterface
    {
        return new readonly class ($candidate) implements AccountOnlyFeedInterface {
            public function __construct(private ?FeedCandidateModel $candidate)
            {
            }

            public function candidateFor(User $user, string $url): ?FeedCandidateModel
            {
                return $this->candidate;
            }
        };
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

    public function testAThrottledSiteIsAScrapeFailureNotAnError(): void
    {
        $found = $this->service(new ThrottledFeedDiscovery())
            ->discover($this->user('throttled@example.test'), 'https://example.com/');

        self::assertSame([], $found->candidates);
        self::assertSame(ScrapeFailureReason::Throttled, $found->scrapeFailureReason);
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

    public function testAnAccountOnlyFeedAnswersBeforeDiscoveryIsAsked(): void
    {
        $hidden = new FeedCandidateModel(
            'https://bsky.app/profile/rusty-17.bsky.social',
            'Rusty',
            SourceFormat::BLUESKY_API,
            NoPreviewReason::HiddenProfile,
        );
        $discovery = new RecordingFeedDiscovery(FeedDiscoveryResultModel::candidates([]));

        $found = $this->service($discovery, [$this->offering(null), $this->offering($hidden)])
            ->discover($this->user('hidden@example.test'), 'https://bsky.app/profile/rusty-17.bsky.social');

        self::assertEquals([new PreviewedCandidateModel($hidden)], $found->candidates);
        self::assertSame([], $discovery->urls);
    }

    public function testADecliningAccountOnlyFeedLeavesTheUrlToDiscovery(): void
    {
        $discovery = new RecordingFeedDiscovery(FeedDiscoveryResultModel::candidates([]));

        $this->service($discovery, [$this->offering(null)])
            ->discover($this->user('declined@example.test'), 'https://example.com/');

        self::assertSame(['https://example.com/'], $discovery->urls);
    }
}
