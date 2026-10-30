<?php

declare(strict_types=1);

namespace App\Service\Chapter;

use App\Entity\Entry;
use App\Service\Chapter\Model\ServedChaptersModel;
use App\Service\Chapter\Support\DescriptionChapters;
use App\Service\Url\Support\YouTubeUrl;

/** The chapters an opened entry shows, and the body to show beside them; a video's or an undeclared episode's come from its description. Opening may store a fetched chapters file. */
final readonly class ChaptersOnOpen
{
    public function __construct(private ChapterFiller $filler)
    {
    }

    public function serve(Entry $entry): ServedChaptersModel
    {
        $body = $entry->getContentHtml();
        if ($body !== null && self::isYouTubeVideo($entry->getUrl())) {
            return DescriptionChapters::in($body);
        }

        $this->filler->fillOnOpen($entry);
        if ($body !== null && self::isEpisodeWithoutChapters($entry)) {
            return DescriptionChapters::inEpisode($body);
        }

        return new ServedChaptersModel($entry->getChapters(), $body);
    }

    private static function isEpisodeWithoutChapters(Entry $entry): bool
    {
        return $entry->getAttachments() !== [] && $entry->getChapters() === [];
    }

    private static function isYouTubeVideo(?string $url): bool
    {
        $video = YouTubeUrl::video($url);

        return $video !== null && !$video->isShort;
    }
}
