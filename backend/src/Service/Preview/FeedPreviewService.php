<?php

declare(strict_types=1);

namespace App\Service\Preview;

use App\Exception\FeedPreviewException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;
use App\Service\Parser\ParsedEntry;
use App\Service\Scraper\HtmlItemExtractor;

/**
 * Fetches a feed URL and summarizes its content shape — how many items it has,
 * whether they carry full articles or bare titles, and a handful of sample
 * items — so a caller can preview a feed before subscribing to it.
 */
final readonly class FeedPreviewService
{
    private const SAMPLE_SIZE = 4;
    private const FULL_TEXT_MIN = 600;
    private const SNIPPET_LEN = 200;

    /** Richest tier first: ties in the verdict resolve to whichever comes first here. */
    private const TIERS_BY_RICHNESS = ['full', 'summary', 'title-only'];

    public function __construct(
        private FeedFetcherInterface $fetcher,
        private FeedParser $parser,
        private HtmlItemExtractor $extractor,
    ) {
    }

    public function preview(string $url, ?string $format = null): FeedPreview
    {
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
            // both branches: HtmlExtractionException IS a FeedParseException.
            $feed = $format === 'scraped'
                ? $this->extractor->extract($body, $response->finalUrl)
                : $this->parser->parse($body);
        } catch (FeedParseException $e) {
            throw new FeedPreviewException('That address is not a readable feed.', 0, $e);
        }

        $sample = \array_slice($feed->entries, 0, self::SAMPLE_SIZE);
        $items = array_map(fn (ParsedEntry $e): FeedPreviewItem => $this->item($e), $sample);
        $tiers = array_map(fn (ParsedEntry $e): string => $this->tier($e), $sample);

        return new FeedPreview(
            title: $feed->title,
            itemCount: \count($feed->entries),
            content: $this->verdict($tiers),
            hasImages: array_any($items, static fn (FeedPreviewItem $i): bool => $i->hasImage),
            items: $items,
        );
    }

    private function item(ParsedEntry $entry): FeedPreviewItem
    {
        $text = $this->plainText($entry);

        return new FeedPreviewItem(
            title: $entry->title,
            publishedAt: $entry->publishedAt,
            author: $entry->author,
            hasImage: $entry->imageUrl !== null,
            textLength: mb_strlen($text),
            snippet: $this->snippet($text),
        );
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
        $html = $entry->contentHtml ?? $entry->summary;
        if ($html === null || $html === '') {
            return '';
        }

        $decoded = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $collapsed = preg_replace('/\s+/u', ' ', $decoded) ?? $decoded;

        return trim($collapsed);
    }

    private function snippet(string $text): string
    {
        if (mb_strlen($text) <= self::SNIPPET_LEN) {
            return $text;
        }

        $truncated = mb_substr($text, 0, self::SNIPPET_LEN);
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return rtrim($truncated) . '…';
    }
}
