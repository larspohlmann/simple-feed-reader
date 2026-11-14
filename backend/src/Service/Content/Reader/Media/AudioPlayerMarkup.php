<?php

declare(strict_types=1);

namespace App\Service\Content\Reader\Media;

use App\Service\Content\Reader\Media\Model\AudioClipModel;
use App\Service\Content\Reader\Media\Support\ClipDuration;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * The one shape every body <audio> takes: the client swaps it for its Listen row by `title` and the `<time>` inside,
 * and a client without that upgrade still plays it.
 */
final readonly class AudioPlayerMarkup
{
    public function element(HTMLDocument $document, AudioClipModel $clip): Element
    {
        $player = $document->createElement('audio');
        $player->setAttribute('controls', '');
        // Never fetch megabytes for an article the reader may only be skimming.
        $player->setAttribute('preload', 'none');
        $player->setAttribute('src', $clip->url);
        $title = trim($clip->title ?? '');
        if ($title !== '') {
            $player->setAttribute('title', $title);
        }
        // The client keys its compact, translated presentation off this class, a mark both sanitizers keep.
        if ($clip->narrated) {
            $player->setAttribute('class', 'reader-narration');
        }
        if ($clip->durationSeconds !== null) {
            $player->append($this->duration($document, $clip->durationSeconds));
        }

        return $player;
    }

    // Angular's [innerHTML] sanitizer strips every data-* attribute; a <time> child crosses it and both sanitizers.
    private function duration(HTMLDocument $document, int $seconds): Element
    {
        $time = $document->createElement('time');
        $time->setAttribute('datetime', ClipDuration::toIso($seconds));
        $time->textContent = ClipDuration::clock($seconds);

        return $time;
    }
}
