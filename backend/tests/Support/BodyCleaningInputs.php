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
        return new BodyCleaningInput([], self::noLeadImage(), ArticleMedia::none(), FeedMedia::none());
    }

    /** @param list<string|null> $titleCandidates */
    public static function withTitles(array $titleCandidates): BodyCleaningInput
    {
        return new BodyCleaningInput($titleCandidates, self::noLeadImage(), ArticleMedia::none(), FeedMedia::none());
    }

    public static function withLeadImage(LeadImageCandidate $leadImage): BodyCleaningInput
    {
        return new BodyCleaningInput([], $leadImage, ArticleMedia::none(), FeedMedia::none());
    }

    public static function withMedia(ArticleMedia $media): BodyCleaningInput
    {
        return new BodyCleaningInput([], self::noLeadImage(), $media, FeedMedia::none());
    }

    public static function withFeedMedia(FeedMedia $feedMedia): BodyCleaningInput
    {
        return new BodyCleaningInput([], self::noLeadImage(), ArticleMedia::none(), $feedMedia);
    }

    public static function withEntryAuthor(?string $entryAuthor): BodyCleaningInput
    {
        return new BodyCleaningInput(
            [],
            self::noLeadImage(),
            ArticleMedia::none(),
            FeedMedia::none(),
            entryAuthor: $entryAuthor,
        );
    }

    /** @param list<Slideshow> $slideshows */
    public static function withSlideshows(array $slideshows): BodyCleaningInput
    {
        return new BodyCleaningInput(
            [],
            self::noLeadImage(),
            ArticleMedia::none(),
            FeedMedia::none(),
            slideshows: $slideshows,
        );
    }

    /** @param list<TeaserPlayer> $teasers the teasers to rebuild, beside the media the pipeline already placed */
    public static function withTeasers(array $teasers, ArticleMedia $placedMedia): BodyCleaningInput
    {
        return new BodyCleaningInput([], self::noLeadImage(), $placedMedia, FeedMedia::none(), teasers: $teasers);
    }

    public static function withExcerpt(?string $excerpt): BodyCleaningInput
    {
        return new BodyCleaningInput(
            [],
            self::noLeadImage(),
            ArticleMedia::none(),
            FeedMedia::none(),
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
