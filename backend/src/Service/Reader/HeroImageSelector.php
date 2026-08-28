<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Image\DeclaredImage;
use Dom\Element;

/**
 * Decides whether the feed's own picture may lead the original (feed-body) view
 * as its hero. The reader view no longer uses this — its lead picture is
 * restored into the extracted body during extraction (#681) — so the one caller
 * is OriginalHeroResolver and the body judged here is always the feed's own.
 *
 * A hero exists only to give a lead picture to a body that has none. So the rule
 * is source-blind and deliberately coarse (#657): the candidate stands only when
 * the body carries no image at all; any `<img>` suppresses it. This is what
 * remains of a longer history that once tried to show the hero unless the body
 * repeated *that same photo* by CDN identity (#520/#590/#608/#610/#619/#625/#653);
 * a photo served as two unrelated CDN files defeats URL identity, so that
 * matching was dropped and "the body has a picture" is the signal that holds.
 *
 * The candidate is guarded to http(s) so a javascript:/data: URL from the feed
 * can never reach the client's <img src>.
 */
final class HeroImageSelector
{
    public function select(?DeclaredImage $candidate, string $bodyHtml): ?DeclaredImage
    {
        if ($candidate === null || preg_match('#^https?://#i', $candidate->url) !== 1) {
            return null;
        }

        // Blank or unparsable html leaves no body to judge, so the candidate
        // stands. The parser wraps a bare fragment in <html><body> on its own.
        $body = HtmlDocumentParser::parseOrNull($bodyHtml)?->body;
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
