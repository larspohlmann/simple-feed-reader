<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Reader\BodyCleaning\Model\BodyCleaningInputModel;
use App\Service\Reader\Media\Model\ArticleMediaModel;
use App\Service\Reader\Media\Teaser\Model\TeaserPlayerModel;
use App\Service\Reader\Model\FeedMediaModel;
use App\Service\Reader\Model\LeadImageCandidateModel;
use App\Service\Reader\Model\PageImageInventoryModel;
use App\Service\Reader\Slideshow\Model\SlideshowModel;

final class BodyCleaningInputs
{
    public static function nothingKnown(): BodyCleaningInputModel
    {
        return self::build();
    }

    /** @param list<string|null> $titleCandidates */
    public static function withTitles(array $titleCandidates): BodyCleaningInputModel
    {
        return self::build(titleCandidates: $titleCandidates);
    }

    public static function withLeadImage(LeadImageCandidateModel $leadImage): BodyCleaningInputModel
    {
        return self::build(leadImage: $leadImage);
    }

    public static function withMedia(ArticleMediaModel $media): BodyCleaningInputModel
    {
        return self::build(media: $media);
    }

    public static function withLeadImageAndMedia(
        LeadImageCandidateModel $leadImage,
        ArticleMediaModel $media,
    ): BodyCleaningInputModel {
        return self::build(leadImage: $leadImage, media: $media);
    }

    public static function withFeedMedia(FeedMediaModel $feedMedia): BodyCleaningInputModel
    {
        return self::build(feedMedia: $feedMedia);
    }

    public static function withEntryAuthor(?string $entryAuthor): BodyCleaningInputModel
    {
        return self::build(entryAuthor: $entryAuthor);
    }

    /** @param list<SlideshowModel> $slideshows */
    public static function withSlideshows(array $slideshows): BodyCleaningInputModel
    {
        return self::build(slideshows: $slideshows);
    }

    /** @param list<TeaserPlayerModel> $teasers the teasers to rebuild, beside the media the pipeline already placed */
    public static function withTeasers(array $teasers, ArticleMediaModel $placedMedia): BodyCleaningInputModel
    {
        return self::build(media: $placedMedia, teasers: $teasers);
    }

    public static function withExcerpt(?string $excerpt): BodyCleaningInputModel
    {
        return self::build(excerpt: $excerpt);
    }

    /**
     * @param list<string|null>       $titleCandidates
     * @param list<SlideshowModel>    $slideshows
     * @param list<TeaserPlayerModel> $teasers
     */
    private static function build(
        array $titleCandidates = [],
        ?LeadImageCandidateModel $leadImage = null,
        ?ArticleMediaModel $media = null,
        ?FeedMediaModel $feedMedia = null,
        ?string $entryAuthor = null,
        array $slideshows = [],
        array $teasers = [],
        ?string $excerpt = null,
    ): BodyCleaningInputModel {
        return new BodyCleaningInputModel(
            $titleCandidates,
            $leadImage ?? self::noLeadImage(),
            $media ?? ArticleMediaModel::none(),
            $feedMedia ?? FeedMediaModel::none(),
            entryAuthor: $entryAuthor,
            slideshows: $slideshows,
            teasers: $teasers,
            excerpt: $excerpt,
        );
    }

    public static function noLeadImage(): LeadImageCandidateModel
    {
        return new LeadImageCandidateModel(null, self::pageDrawingNothing());
    }

    public static function pageDrawingNothing(): PageImageInventoryModel
    {
        return PageImageInventoryModel::fromDocument(HtmlDocumentParser::parse('<body></body>'));
    }
}
