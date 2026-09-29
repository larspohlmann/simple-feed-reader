<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Model;

use App\Service\Html\Support\HtmlDocumentParser;
use Dom\HTMLDocument;

/** One parse of the raw article page, shared by every MediaCandidateSource. */
final readonly class RawPageModel
{
    private function __construct(
        public HTMLDocument $document,
        public string $html,
        public string $url,
        public PageTextBlocksModel $blocks,
    ) {
    }

    public static function parse(string $html, string $url): self
    {
        $document = HtmlDocumentParser::parseOrEmpty($html);

        return new self($document, $html, $url, PageTextBlocksModel::fromDocument($document));
    }
}
