<?php

declare(strict_types=1);

namespace App\Service\Preview\Factory;

use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Ingest\Support\EntrySnippet;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Preview\Model\FeedPreviewItemModel;
use App\Service\Preview\Model\FeedPreviewModel;
use App\Service\Text\Support\PlainText;

final readonly class FeedPreviewFactory
{
    // The content/has-images verdict reads a wider sample than the dialog shows,
    // so the badges reflect the feed, not just its first few rendered rows.
    private const int SAMPLE_SIZE = 8;
    private const int PREVIEW_ITEMS = 3;
    private const int FULL_TEXT_MIN = 600;

    /** Richest tier first: ties in the verdict resolve to whichever comes first here. */
    private const array TIERS_BY_RICHNESS = ['full', 'summary', 'title-only'];

    public function fromDocument(ParsedFeedModel $feed): FeedPreviewModel
    {
        $sample = \array_slice($feed->entries, 0, self::SAMPLE_SIZE);
        $tiers = array_map(fn (ParsedEntryModel $entry): string => $this->tier($entry), $sample);
        $displayed = \array_slice($sample, 0, self::PREVIEW_ITEMS);
        $items = array_map(fn (ParsedEntryModel $entry): FeedPreviewItemModel => $this->item($entry), $displayed);

        return new FeedPreviewModel(
            title: $feed->title,
            itemCount: \count($feed->entries),
            content: $this->verdict($tiers),
            hasImages: array_any(
                $sample,
                fn (ParsedEntryModel $entry): bool => $this->httpsImage($entry->media->image) !== null,
            ),
            items: $items,
        );
    }

    private function item(ParsedEntryModel $entry): FeedPreviewItemModel
    {
        $image = $this->httpsImage($entry->media->image);

        return new FeedPreviewItemModel(
            title: $entry->headline->text,
            url: $entry->url,
            author: $entry->author,
            // Prefers the feed's own summary/teaser field; tier()/plainText() deliberately
            // reverse this precedence (contentHtml ?? summary) to measure the full body.
            summary: EntrySnippet::from($entry->summary ?? $entry->contentHtml),
            imageUrl: $image?->url,
            imageWidth: $image?->width,
            imageHeight: $image?->height,
            publishedAt: $entry->publishedAt,
        );
    }

    // The SPA is https, so an http/relative/data image is useless in an <img>.
    private function httpsImage(?DeclaredImageModel $image): ?DeclaredImageModel
    {
        if ($image === null) {
            return null;
        }

        return str_starts_with($image->url, 'https://') ? $image : null;
    }

    private function tier(ParsedEntryModel $entry): string
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

    private function plainText(ParsedEntryModel $entry): string
    {
        return PlainText::from($entry->contentHtml ?? $entry->summary) ?? '';
    }
}
