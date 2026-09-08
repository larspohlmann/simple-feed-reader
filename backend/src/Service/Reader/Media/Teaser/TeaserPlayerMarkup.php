<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Teaser;

use App\Service\Reader\Media\MediaKind;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Rebuilds an inline teaser as one figure the sanitizer keeps: the player, its
 * still, and the headline as a link. class="reader-teaser" is the client's
 * marker; it survives on <figure> alone (EntrySanitizer). <audio> shows no
 * poster, so an audio teaser keeps its still as an <img> beside the control.
 */
final readonly class TeaserPlayerMarkup
{
    public function figureFor(HTMLDocument $document, TeaserPlayer $teaser): Element
    {
        $figure = $document->createElement('figure');
        $figure->setAttribute('class', 'reader-teaser');
        $this->appendPlayer($document, $figure, $teaser);
        if ($teaser->caption !== null) {
            $figure->appendChild($this->caption($document, $teaser));
        }

        return $figure;
    }

    private function appendPlayer(HTMLDocument $document, Element $figure, TeaserPlayer $teaser): void
    {
        if ($teaser->kind === MediaKind::Audio) {
            $figure->appendChild($this->still($document, $teaser));
            $figure->appendChild($this->player($document, 'audio', $teaser->mediaUrl));

            return;
        }

        $video = $this->player($document, 'video', $teaser->mediaUrl);
        $video->setAttribute('poster', $teaser->posterUrl);
        $figure->appendChild($video);
    }

    private function player(HTMLDocument $document, string $tag, string $mediaUrl): Element
    {
        $player = $document->createElement($tag);
        $player->setAttribute('controls', '');
        $player->setAttribute('preload', 'none');
        $player->setAttribute('src', $mediaUrl);

        return $player;
    }

    private function still(HTMLDocument $document, TeaserPlayer $teaser): Element
    {
        $image = $document->createElement('img');
        $image->setAttribute('src', $teaser->posterUrl);
        $image->setAttribute('alt', $teaser->caption ?? '');
        $image->setAttribute('loading', 'lazy');

        return $image;
    }

    /** A linked caption is an <a> (the sanitizer forces rel/target); a plain one keeps only its text. */
    private function caption(HTMLDocument $document, TeaserPlayer $teaser): Element
    {
        $caption = $document->createElement('figcaption');
        if ($teaser->linkUrl === null) {
            $caption->appendChild($document->createTextNode((string) $teaser->caption));

            return $caption;
        }

        $link = $document->createElement('a');
        $link->setAttribute('href', $teaser->linkUrl);
        $link->appendChild($document->createTextNode((string) $teaser->caption));
        $caption->appendChild($link);

        return $caption;
    }
}
