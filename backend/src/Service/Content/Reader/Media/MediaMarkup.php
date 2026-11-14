<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Reader\Media\Model\EmbedTargetModel;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Every recovered embed as a link to its durable embed URL, with the provider's poster inside; the reader upgrades it
 * to a player at render. Never an iframe: EntrySanitizer is shared with feed ingest, so any feed could inject one.
 */
final readonly class MediaMarkup
{
    public function embedLink(HTMLDocument $document, EmbedTargetModel $target): Element
    {
        $link = $document->createElement('a');
        $link->setAttribute('href', $target->url);

        $poster = $target->posterUrl;
        if ($poster === null) {
            $link->appendChild($document->createTextNode($target->label));

            return $link;
        }

        $image = $document->createElement('img');
        $image->setAttribute('src', $poster);
        $image->setAttribute('alt', $target->label);
        $link->appendChild($image);

        return $link;
    }
}
