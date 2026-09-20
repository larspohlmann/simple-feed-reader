<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Html\HtmlDocumentParser;
use Dom\HTMLDocument;

/**
 * One read of the raw article page, shared by every MediaCandidateSource: the
 * markup, a single parse of it, and the prose blocks derived from that parse.
 * Discovery reads the raw page on purpose — normalize strips media before it
 * runs (#748) — but the sources no longer each re-parse it (#1090).
 */
final readonly class RawPage
{
    private function __construct(
        public ?HTMLDocument $document,
        public string $html,
        public string $url,
        public PageTextBlocks $blocks,
    ) {
    }

    public static function parse(string $html, string $url): self
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        $blocks = $document !== null ? PageTextBlocks::fromDocument($document) : PageTextBlocks::none();

        return new self($document, $html, $url, $blocks);
    }
}
