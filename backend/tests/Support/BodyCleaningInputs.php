<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\BodyCleaning\BodyCleaningInput;
use App\Service\Reader\FeedMedia;
use App\Service\Reader\LeadImageCandidate;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\Teaser\TeaserPlayer;
use App\Service\Reader\PageImageInventory;
use App\Service\Reader\Slideshow\Slideshow;

final class BodyCleaningInputs
{
    public static function nothingKnown(): BodyCleaningInput
    {
        return self::build();
    }

    /** @param list<string|null> $titleCandidates */
    public static function withTitles(array $titleCandidates): BodyCleaningInput
    {
        return self::build(titleCandidates: $titleCandidates);
    }

    public static function withLeadImage(LeadImageCandidate $leadImage): BodyCleaningInput
    {
        return self::build(leadImage: $leadImage);
    }

    public static function withMedia(ArticleMedia $media): BodyCleaningInput
    {
        return self::build(media: $media);
    }

    public static function withLeadImageAndMedia(LeadImageCandidate $leadImage, ArticleMedia $media): BodyCleaningInput
    {
        return self::build(leadImage: $leadImage, media: $media);
    }

    public static function withFeedMedia(FeedMedia $feedMedia): BodyCleaningInput
    {
        return self::build(feedMedia: $feedMedia);
    }

    public static function withEntryAuthor(?string $entryAuthor): BodyCleaningInput
    {
        return self::build(entryAuthor: $entryAuthor);
    }

    /** @param list<Slideshow> $slideshows */
    public static function withSlideshows(array $slideshows): BodyCleaningInput
    {
        return self::build(slideshows: $slideshows);
    }

    /** @param list<TeaserPlayer> $teasers the teasers to rebuild, beside the media the pipeline already placed */
    public static function withTeasers(array $teasers, ArticleMedia $placedMedia): BodyCleaningInput
    {
        return self::build(media: $placedMedia, teasers: $teasers);
    }

    public static function withExcerpt(?string $excerpt): BodyCleaningInput
    {
        return self::build(excerpt: $excerpt);
    }

    /**
     * @param list<string|null>  $titleCandidates
     * @param list<Slideshow>    $slideshows
     * @param list<TeaserPlayer> $teasers
     */
    private static function build(
        array $titleCandidates = [],
        ?LeadImageCandidate $leadImage = null,
        ?ArticleMedia $media = null,
        ?FeedMedia $feedMedia = null,
        ?string $entryAuthor = null,
        array $slideshows = [],
        array $teasers = [],
        ?string $excerpt = null,
    ): BodyCleaningInput {
        return new BodyCleaningInput(
            $titleCandidates,
            $leadImage ?? self::noLeadImage(),
            $media ?? ArticleMedia::none(),
            $feedMedia ?? FeedMedia::none(),
            entryAuthor: $entryAuthor,
            slideshows: $slideshows,
            teasers: $teasers,
            excerpt: $excerpt,
        );
    }

    public static function noLeadImage(): LeadImageCandidate
    {
        return new LeadImageCandidate(null, self::pageDrawingNothing());
    }

    public static function pageDrawingNothing(): PageImageInventory
    {
        return PageImageInventory::fromDocument(HtmlDocumentParser::parse('<body></body>'));
    }
}
