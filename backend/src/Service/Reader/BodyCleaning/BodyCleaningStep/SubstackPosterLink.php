<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Links a Substack YouTube poster to its video: the poster URL carries the video id, so no fetch and no new host.
 * An image already inside a link is skipped, so the poster anchor SubstackGatedVideoPlaceholder built stays as it is.
 */
final readonly class SubstackPosterLink implements BodyCleaningStepInterface
{
    private const string POSTER_PATTERN =
        '#^https://substackcdn\.com/image/youtube/[^/]+/([A-Za-z0-9_-]{11})$#';

    public function cleanIn(BodyCleaningPass $pass): void
    {
        $this->linkIn($pass->document);
    }

    private function linkIn(HTMLDocument $body): void
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

        return preg_match(self::POSTER_PATTERN, $image->getAttribute('src') ?? '', $matches) === 1 ? $matches[1] : null;
    }
}
