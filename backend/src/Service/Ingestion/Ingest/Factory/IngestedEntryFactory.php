<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest\Factory;

use App\Entity\Entry;
use App\Entity\EntryAuthor;
use App\Entity\EntryChapters;
use App\Entity\EntryText;
use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Foundation\Sanitize\EntrySanitizer;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Foundation\Url\Support\TrackingFreeUrl;
use App\Service\Ingestion\Ingest\EntryImageWriter;
use App\Service\Ingestion\Ingest\Model\IncomingEntryModel;
use App\Service\Ingestion\Ingest\Pass\FeedIngestContext;
use App\Service\Ingestion\Ingest\Support\EntryEffectiveDate;
use App\Service\Ingestion\Ingest\Support\EntryMediaAssembler;
use App\Service\Ingestion\Ingest\Support\EntrySnippet;
use App\Service\Ingestion\Language\SocialEntryLanguage;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Model\ParsedMediaBundleModel;

/** Builds the Entry row for an incoming item, cut to its column limits. It persists nothing. */
final readonly class IngestedEntryFactory
{
    private const int AUTHOR_MAX = 255;
    private const int URL_MAX = 2048;

    public function __construct(
        private EntrySanitizer $sanitizer,
        private EntryImageWriter $imageWriter,
        private SocialEntryLanguage $socialLanguage,
    ) {
    }

    public function create(Feed $feed, IncomingEntryModel $incoming, FeedIngestContext $context): Entry
    {
        $parsed = $incoming->parsed;
        $entry = new Entry(
            $feed,
            $parsed->guid,
            self::cut(TrackingFreeUrl::orNull($parsed->url), self::URL_MAX),
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
        $entry->recordText($this->completeText($entry->getText(), $feed, $parsed));
        $entry->setPublishedAt($parsed->publishedAt);
        $entry->setDiscussion($parsed->discussion);
        $entry->recordDiscussionCounts($parsed->discussionCounts);
        $this->imageWriter->writeOrMarkNone($entry, $parsed->media->image);
        self::attachMedia($entry, $parsed);
        self::declareChapters($entry, $parsed);

        return $entry;
    }

    private function completeText(EntryText $text, Feed $feed, ParsedEntryModel $parsed): EntryText
    {
        $written = $text
            ->withSummary(EntrySnippet::from($parsed->summary ?? $parsed->contentHtml))
            ->withContentHtml($this->sanitizer->sanitize($parsed->contentHtml));
        if (!SourceFormat::isSocial($feed->getSourceFormat())) {
            return $written;
        }

        return $written->withLanguage($this->socialLanguage->resolveLanguage($parsed));
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
