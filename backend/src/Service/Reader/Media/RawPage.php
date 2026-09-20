<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Html\HtmlDocumentParser;
use Dom\HTMLDocument;

/** One parse of the raw article page, shared by every MediaCandidateSource. */
final readonly class RawPage
{
    private function __construct(
        public HTMLDocument $document,
        public string $html,
        public string $url,
        public PageTextBlocks $blocks,
    ) {
    }

    public static function parse(string $html, string $url): self
    {
        $document = HtmlDocumentParser::parseOrNull($html) ?? HTMLDocument::createEmpty();

        return new self($document, $html, $url, PageTextBlocks::fromDocument($document));
    }
}
