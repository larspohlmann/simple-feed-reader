<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Builds the one markup shape every recovered embed uses: a link to the durable
 * embed URL, with a poster inside it when the provider has one.
 *
 * A link, not an iframe. EntrySanitizer is shared with feed ingest, so allowing
 * iframes there would let any feed inject one; the reader upgrades this link to
 * a real player at render instead.
 */
final readonly class MediaMarkup
{
    public function embedLink(HTMLDocument $document, EmbedTarget $target): Element
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
