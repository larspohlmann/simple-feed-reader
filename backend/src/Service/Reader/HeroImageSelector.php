<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Url\Support\AbsoluteHttpUrl;
use Dom\Element;

/**
 * Whether the feed's own picture may lead the original (feed-body) view as its hero. The rule is source-blind and
 * deliberately coarse (#657): any `<img>` in the body suppresses the candidate, since a photo served as two unrelated
 * CDN files defeats URL identity. Only an http(s) candidate passes, so no javascript:/data: URL reaches the client.
 */
final readonly class HeroImageSelector
{
    /**
     * Below this a known width only upscales into the hero band; an inline
     * image's width is the publisher's display width, which is the honest
     * bound.
     */
    private const int MIN_HERO_WIDTH = 480;

    public function select(?DeclaredImageModel $candidate, string $bodyHtml): ?DeclaredImageModel
    {
        if ($candidate === null || !AbsoluteHttpUrl::matches($candidate->url)) {
            return null;
        }

        if ($candidate->width !== null && $candidate->width < self::MIN_HERO_WIDTH) {
            return null;
        }

        // Blank or unparsable html leaves no body to judge, so the candidate
        // stands. The parser wraps a bare fragment in <html><body> on its own.
        $body = HtmlDocumentParser::parseOrEmpty($bodyHtml)->body;
        if ($body === null || !$this->bodyContainsImage($body)) {
            return $candidate;
        }

        return null;
    }

    private function bodyContainsImage(Element $body): bool
    {
        return $body->getElementsByTagName('img')->length > 0;
    }
}
