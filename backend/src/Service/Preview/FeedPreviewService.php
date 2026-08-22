<?php

declare(strict_types=1);

namespace App\Service\Preview;

use App\Entity\User;
use App\Enum\SourceFormat;
use App\Exception\FeedPreviewException;
use App\Service\Discovery\ScrapeFallbackPolicy;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Ingest\EntrySnippet;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;
use App\Service\Parser\ParsedEntry;
use App\Service\Parser\ParsedImage;
use App\Service\Parser\WordPressJsonParser;
use App\Service\Scraper\HtmlItemExtractor;
use App\Service\Text\PlainText;

/**
 * Fetches a feed URL and summarizes its content shape — how many items it has,
 * whether they carry full articles or bare titles, and a handful of sample
 * items — so a caller can preview a feed before subscribing to it.
 */
final readonly class FeedPreviewService
{
    // The content/has-images verdict reads a wider sample than the dialog shows,
    // so the badges reflect the feed, not just its first few rendered rows.
    private const int SAMPLE_SIZE = 8;
    private const int PREVIEW_ITEMS = 3;
    private const int FULL_TEXT_MIN = 600;

    /** Richest tier first: ties in the verdict resolve to whichever comes first here. */
    private const array TIERS_BY_RICHNESS = ['full', 'summary', 'title-only'];

    public function __construct(
        private FeedFetcherInterface $fetcher,
        private FeedParser $parser,
        private HtmlItemExtractor $extractor,
        private ScrapeFallbackPolicy $scrapeFallbackPolicy,
        private WordPressJsonParser $wordPressJsonParser,
    ) {
    }

    public function preview(User $user, string $url, ?string $format = null): FeedPreview
    {
        // Mirrors the guard in SubscriptionService::subscribe(): a preview
        // request asserting 'scraped' is the same hand-made bypass discovery's
        // gate (Task 5) cannot see, since discovery never runs on this path.
        if (SourceFormat::SCRAPED === $format) {
            $this->scrapeFallbackPolicy->assertMayScrape($user);
        }

        try {
            $response = $this->fetcher->fetch($url);
        } catch (FetchException $e) {
            throw new FeedPreviewException('The feed could not be loaded.', 0, $e);
        }

        $body = $response->body ?? '';
        if (trim($body) === '') {
            throw new FeedPreviewException('The feed returned an empty document.');
        }

        try {
            // A 'scraped' preview extracts the page's article list — same
            // synthesis the refresh pipeline will run — so the dialog shows
            // what subscribing to the page actually buys. One catch covers
            // all branches: HtmlExtractionException IS a FeedParseException.
            $feed = match ($format) {
                SourceFormat::SCRAPED => $this->extractor->extract($body, $response->finalUrl),
                SourceFormat::WP_JSON => $this->wordPressJsonParser->parse($body),
                default => $this->parser->parse($body),
            };
        } catch (FeedParseException $e) {
            // The generic wording fits a feed-document mismatch; a scraped
            // preview keeps the extractor's own message ("No article list was
            // detected on the page.") — it already names the actual problem
            // in user-appropriate words, so flattening it would only lose
            // information.
            throw new FeedPreviewException(
                $format === SourceFormat::SCRAPED ? $e->getMessage() : 'That address is not a readable feed.',
                0,
                $e,
            );
        }

        $sample = \array_slice($feed->entries, 0, self::SAMPLE_SIZE);
        $tiers = array_map(fn (ParsedEntry $e): string => $this->tier($e), $sample);
        $displayed = \array_slice($sample, 0, self::PREVIEW_ITEMS);
        $items = array_map(fn (ParsedEntry $e): FeedPreviewItem => $this->item($e), $displayed);

        return new FeedPreview(
            title: $feed->title,
            itemCount: \count($feed->entries),
            content: $this->verdict($tiers),
            hasImages: array_any($sample, fn (ParsedEntry $e): bool => $this->httpsImageUrl($e->image) !== null),
            items: $items,
        );
    }

    private function item(ParsedEntry $entry): FeedPreviewItem
    {
        $imageUrl = $this->httpsImageUrl($entry->image);

        return new FeedPreviewItem(
            title: $entry->title,
            url: $entry->url,
            author: $entry->author,
            // Prefers the feed's own summary/teaser field; tier()/plainText() deliberately
            // reverse this precedence (contentHtml ?? summary) to measure the full body.
            summary: EntrySnippet::from($entry->summary ?? $entry->contentHtml),
            imageUrl: $imageUrl,
            imageWidth: $imageUrl === null ? null : $entry->image?->width,
            imageHeight: $imageUrl === null ? null : $entry->image?->height,
            publishedAt: $entry->publishedAt,
        );
    }

    // The SPA is https, so an http/relative/data image is useless in an <img>.
    // Mirrors the reader's firstPreviewImage rule.
    private function httpsImageUrl(?ParsedImage $image): ?string
    {
        if ($image === null) {
            return null;
        }

        return str_starts_with($image->url, 'https://') ? $image->url : null;
    }

    private function tier(ParsedEntry $entry): string
    {
        $text = $this->plainText($entry);
        if ($entry->contentHtml !== null && mb_strlen($text) >= self::FULL_TEXT_MIN) {
            return 'full';
        }

        return $text === '' ? 'title-only' : 'summary';
    }

    /**
     * @param list<string> $tiers
     * @return 'full'|'summary'|'title-only'
     */
    private function verdict(array $tiers): string
    {
        $counts = array_count_values($tiers);

        // Default to the least-rich tier so an empty feed reads as 'title-only'
        // rather than inheriting the richest tier's name with a zero count.
        $best = 'title-only';
        $bestCount = 0;
        foreach (self::TIERS_BY_RICHNESS as $tier) {
            $count = $counts[$tier] ?? 0;
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $tier;
            }
        }

        return $best;
    }

    private function plainText(ParsedEntry $entry): string
    {
        return PlainText::from($entry->contentHtml ?? $entry->summary) ?? '';
    }
}
