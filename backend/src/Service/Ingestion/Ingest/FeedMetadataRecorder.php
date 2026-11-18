<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest;

use App\Entity\Feed;
use App\Enum\FeedPlatform;
use App\Service\Ingestion\Ingest\FeedTitle\FeedTitleInterface;
use App\Service\Ingestion\Language\FeedLanguageResolver;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;

/** Refreshes the feed's own metadata from a parse: title, site, description, logo and traits. The caller flushes. */
final readonly class FeedMetadataRecorder
{
    private const int FEED_TITLE_MAX = 512;
    private const int SITE_URL_MAX = 2048;

    /**
     * The only bound on the TEXT column, which the sidebar bootstrap reduces to plain text for every subscription on
     * every load. Generous: the longest description in a 111-feed library is 617 characters.
     */
    private const int FEED_DESCRIPTION_MAX = 4000;

    public function __construct(
        private FeedTitleInterface $feedTitle,
        private FeedLanguageResolver $languageResolver,
    ) {
    }

    public function record(Feed $feed, ParsedFeedModel $parsed): void
    {
        $this->recordTraits($feed, $parsed);
        if ($parsed->title !== null) {
            $title = $this->feedTitle->of($feed->getUrl(), $parsed->title);
            $feed->setTitle(mb_substr($title, 0, self::FEED_TITLE_MAX));
        }
        if ($parsed->siteUrl !== null) {
            $feed->setSiteUrl(mb_substr($parsed->siteUrl, 0, self::SITE_URL_MAX));
        }
        if ($parsed->description !== null) {
            $feed->setDescription(mb_substr($parsed->description, 0, self::FEED_DESCRIPTION_MAX));
        }
        // A fetch without <image> must not erase the logo.
        if ($parsed->imageUrl !== null) {
            $feed->setImageUrl($parsed->imageUrl);
        }
    }

    private function recordTraits(Feed $feed, ParsedFeedModel $parsed): void
    {
        $traits = $feed->getTraits()
            ->withPlatform(FeedPlatform::fromGenerator($parsed->generator))
            ->withLanguage($this->languageResolver->languageFor($feed, $parsed));
        $feed->recordTraits($traits);
    }
}
