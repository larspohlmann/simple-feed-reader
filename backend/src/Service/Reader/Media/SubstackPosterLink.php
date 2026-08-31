<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Substack strips the YouTube iframe but leaves its poster, and that poster's
 * URL contains the video id — so a dead thumbnail becomes a working link with
 * no re-fetch and no new host trust.
 *
 * An image already inside a link is skipped: #627's gated placeholder inserts
 * its own poster anchor before readability, and this rule must not touch it.
 */
final readonly class SubstackPosterLink
{
    private const string POSTER_PATTERN =
        '#^https://substackcdn\.com/image/youtube/[^/]+/([A-Za-z0-9_-]{11})$#';

    public function linkIn(HTMLDocument $body): void
    {
        foreach (iterator_to_array($body->getElementsByTagName('img')) as $image) {
            $this->linkOne($body, $image);
        }
    }

    private function linkOne(HTMLDocument $body, Element $image): void
    {
        $videoId = $this->videoId($image);
        if ($videoId === null || $image->parentNode === null) {
            return;
        }

        $link = $body->createElement('a');
        $link->setAttribute('href', 'https://www.youtube-nocookie.com/embed/' . $videoId);
        $image->parentNode->replaceChild($link, $image);
        $link->appendChild($image);
        $image->setAttribute('alt', 'Watch on YouTube');
    }

    private function videoId(Element $image): ?string
    {
        if ($image->closest('a') !== null) {
            return null;
        }

        return preg_match(self::POSTER_PATTERN, $image->getAttribute('src') ?? '', $m) === 1 ? $m[1] : null;
    }
}
