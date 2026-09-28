<?php

declare(strict_types=1);

namespace App\Service\Ingest\Factory;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Service\Ingest\EntryEffectiveDate;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Ingest\EntryMediaAssembler;
use App\Service\Ingest\EntrySnippet;
use App\Service\Ingest\FeedIngestContext;
use App\Service\Ingest\Model\IncomingEntryModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedMediaBundleModel;
use App\Service\Sanitize\EntrySanitizer;

/** Builds the Entry row for an incoming item, cut to its column limits. It persists nothing. */
final readonly class IngestedEntryFactory
{
    private const int TITLE_MAX = 1024;
    private const int AUTHOR_MAX = 255;
    private const int URL_MAX = 2048;

    public function __construct(
        private EntrySanitizer $sanitizer,
        private EntryImageWriter $imageWriter,
    ) {
    }

    public function create(Feed $feed, IncomingEntryModel $incoming, FeedIngestContext $context): Entry
    {
        $parsed = $incoming->parsed;
        $entry = new Entry(
            $feed,
            $parsed->guid,
            self::cut($parsed->url, self::URL_MAX),
            mb_substr($parsed->title, 0, self::TITLE_MAX),
            $context->fetchedAt,
            EntryEffectiveDate::for($parsed->publishedAt, $context),
            $incoming->urlHash,
        );
        $entry->setAuthor(self::cut($parsed->author, self::AUTHOR_MAX));
        $entry->setSummary(EntrySnippet::from($parsed->summary ?? $parsed->contentHtml));
        $entry->setContentHtml($this->sanitizer->sanitize($parsed->contentHtml));
        $entry->setPublishedAt($parsed->publishedAt);
        $entry->setDiscussion($parsed->discussion);
        $this->imageWriter->writeOrMarkNone($entry, $parsed->media->image);
        self::attachMedia($entry, $parsed);

        return $entry;
    }

    private static function cut(?string $value, int $maxLength): ?string
    {
        return null === $value ? null : mb_substr($value, 0, $maxLength);
    }

    private static function attachMedia(Entry $entry, ParsedEntryModel $parsed): void
    {
        $bundle = $parsed->media->mediaBundle ?? new ParsedMediaBundleModel();
        $assembled = EntryMediaAssembler::assemble($parsed->media->image, $bundle->media, $bundle->attachments);
        $entry->setMedia($assembled->media, $assembled->attachments);
    }
}
