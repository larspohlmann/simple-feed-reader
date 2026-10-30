<?php

declare(strict_types=1);

namespace App\Service\Chapter;

use App\Entity\Entry;
use App\Entity\EntryAttachment;
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
        $audio = self::audioOf($entry);
        if ($body !== null && $audio !== null && $entry->getChapters() === []) {
            return self::episodeChapters($body, $audio);
        }

        return new ServedChaptersModel($entry->getChapters(), $body);
    }

    private static function episodeChapters(string $body, EntryAttachment $audio): ServedChaptersModel
    {
        if ($audio->durationInSeconds === null) {
            return DescriptionChapters::inEpisode($body);
        }

        return DescriptionChapters::inEpisodeLasting($body, $audio->durationInSeconds);
    }

    private static function audioOf(Entry $entry): ?EntryAttachment
    {
        foreach ($entry->getAttachments() as $attachment) {
            if ($attachment->isAudio()) {
                return $attachment;
            }
        }

        return null;
    }

    private static function isYouTubeVideo(?string $url): bool
    {
        $video = YouTubeUrl::video($url);

        return $video !== null && !$video->isShort;
    }
}
