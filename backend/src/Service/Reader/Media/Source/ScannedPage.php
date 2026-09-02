<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Reader\Media\PageTextBlocks;
use Dom\HTMLDocument;

/**
 * What a scanning layer needs from the page once it has picked a URL: the
 * prose blocks to anchor it to, the page URL its relevance is ranked against,
 * and the Open Graph image that stands in as a poster.
 */
final readonly class ScannedPage
{
    private const string OG_IMAGE = 'meta[property="og:image"]';

    private function __construct(
        public PageTextBlocks $blocks,
        public string $url,
        public ?string $posterUrl,
    ) {
    }

    public static function from(HTMLDocument $document, string $pageUrl): self
    {
        return new self(PageTextBlocks::fromDocument($document), $pageUrl, self::ogImage($document));
    }

    private static function ogImage(HTMLDocument $document): ?string
    {
        $content = $document->querySelector(self::OG_IMAGE)?->getAttribute('content');

        return $content !== null && preg_match('#^https://#i', $content) === 1 ? $content : null;
    }
}
