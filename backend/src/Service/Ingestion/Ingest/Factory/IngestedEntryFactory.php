<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest\Factory;

use App\Entity\Entry;
use App\Entity\EntryAuthor;
use App\Entity\EntryChapters;
use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Foundation\Text\Support\EntryPlainText;
use App\Service\Ingestion\Ingest\EntryImageWriter;
use App\Service\Ingestion\Ingest\Model\IncomingEntryModel;
use App\Service\Ingestion\Ingest\Pass\FeedIngestContext;
use App\Service\Ingestion\Ingest\Support\EntryEffectiveDate;
use App\Service\Ingestion\Ingest\Support\EntryMediaAssembler;
use App\Service\Ingestion\Ingest\Support\EntrySnippet;
use App\Service\Ingestion\Language\LanguageGuess;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Model\ParsedMediaBundleModel;
use App\Service\Foundation\Sanitize\EntrySanitizer;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;

/** Builds the Entry row for an incoming item, cut to its column limits. It persists nothing. */
final readonly class IngestedEntryFactory
{
    private const int AUTHOR_MAX = 255;
    private const int URL_MAX = 2048;

    public function __construct(
        private EntrySanitizer $sanitizer,
        private EntryImageWriter $imageWriter,
        private LanguageGuess $languageGuess,
    ) {
    }

    public function create(Feed $feed, IncomingEntryModel $incoming, FeedIngestContext $context): Entry
    {
        $parsed = $incoming->parsed;
        $entry = new Entry(
            $feed,
            $parsed->guid,
            self::cut($parsed->url, self::URL_MAX),
            $parsed->headline->text,
            $context->fetchedAt,
            EntryEffectiveDate::for($parsed->publishedAt, $context),
            $incoming->urlHash,
        );
        $entry->setHeadline($parsed->headline);
        $entry->setAuthor(new EntryAuthor(
            self::cut($parsed->author, self::AUTHOR_MAX),
            AbsoluteHttpUrl::orNullWithin($parsed->authorUrl, EntryAuthor::URL_MAX_LENGTH),
            AbsoluteHttpUrl::orNullWithin($parsed->authorAvatarUrl, EntryAuthor::URL_MAX_LENGTH),
        ));
        $entry->recordText(
            $entry->getText()->withSummary(EntrySnippet::from($parsed->summary ?? $parsed->contentHtml)),
        );
        $entry->recordText($entry->getText()->withContentHtml($this->sanitizer->sanitize($parsed->contentHtml)));
        $entry->setPublishedAt($parsed->publishedAt);
        $entry->setDiscussion($parsed->discussion);
        $entry->recordDiscussionCounts($parsed->discussionCounts);
        $this->imageWriter->writeOrMarkNone($entry, $parsed->media->image);
        self::attachMedia($entry, $parsed);
        self::declareChapters($entry, $parsed);
        $this->declareLanguage($entry, $feed, $parsed);

        return $entry;
    }

    private function declareLanguage(Entry $entry, Feed $feed, ParsedEntryModel $parsed): void
    {
        if (!SourceFormat::isSocial($feed->getSourceFormat())) {
            return;
        }

        $text = $parsed->headline->text . "\n" . (EntryPlainText::of($parsed->summary) ?? '');
        $entry->recordText($entry->getText()->withLanguage($this->languageGuess->bestFor($text)));
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

    private static function declareChapters(Entry $entry, ParsedEntryModel $parsed): void
    {
        $chapters = ($parsed->media->mediaBundle ?? new ParsedMediaBundleModel())->chapters;
        $entry->declareChapters(
            $chapters->chapters,
            AbsoluteHttpUrl::orNullWithin($chapters->url, EntryChapters::URL_MAX_LENGTH),
        );
    }
}
