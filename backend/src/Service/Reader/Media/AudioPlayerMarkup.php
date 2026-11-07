<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Reader\Media\Model\AudioClipModel;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * The one shape every body <audio> takes: the client swaps it for its Listen row by `title` and `data-duration`, and
 * a client without that upgrade still plays it.
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
        if ($clip->title !== null && trim($clip->title) !== '') {
            $player->setAttribute('title', trim($clip->title));
        }
        if ($clip->durationSeconds !== null) {
            $player->setAttribute('data-duration', (string) $clip->durationSeconds);
        }
        // The client keys its compact, translated presentation off this class, the only mark both sanitizers keep.
        if ($clip->narrated) {
            $player->setAttribute('class', 'reader-narration');
        }

        return $player;
    }
}
