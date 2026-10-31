<?php

declare(strict_types=1);

namespace App\Service\Chapter;

use App\Entity\Entry;
use App\Entity\EntryAttachment;
use App\Service\Chapter\Model\ServedChaptersModel;
use App\Service\Chapter\Support\ChapterSequence;
use App\Service\Chapter\Support\DescriptionChapters;
use App\Service\Url\Support\YouTubeUrl;

final readonly class ChaptersOnOpen
{
    public function __construct(private ChapterFiller $filler)
    {
    }

    public function serve(Entry $entry): ServedChaptersModel
    {
        $served = $this->chaptersOf($entry);

        return new ServedChaptersModel(ChapterSequence::fromZero($served->chapters), $served->contentHtml);
    }

    private function chaptersOf(Entry $entry): ServedChaptersModel
    {
        $body = $entry->getContentHtml();
        if ($body !== null && self::isYouTubeVideo($entry->getUrl())) {
            return DescriptionChapters::in($body);
        }

        $this->filler->fillOnOpen($entry);
        if ($body !== null && $entry->getChapters() === []) {
            $audio = self::audioOf($entry);
            if ($audio !== null) {
                return DescriptionChapters::inEpisode($body, $audio->durationInSeconds);
            }
        }

        return new ServedChaptersModel($entry->getChapters(), $body);
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
