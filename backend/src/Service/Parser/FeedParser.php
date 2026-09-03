<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;

final readonly class FeedParser
{
    public function __construct(
        private FeedParserFactory $parserFactory,
    ) {
    }

    public function parse(string $xml): ParsedFeed
    {
        $feedXml = $this->fromTheDeclaration($xml);

        // loadXML() throws a raw ValueError on an empty string, not false, so
        // guard it here (mirroring HtmlItemExtractor's empty-page check). An empty
        // 200 body is a per-feed parse failure the refresh runner already handles,
        // not an error that 500s the whole run. The guard reads the stripped body:
        // a BOM-only document survives trim() but is empty when loadXML() sees it.
        if ($feedXml === '') {
            throw new FeedParseException('Document is not well-formed XML');
        }

        $document = new \DOMDocument();
        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($feedXml, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        $root = $document->documentElement;
        if ($loaded === false || $root === null) {
            throw new FeedParseException('Document is not well-formed XML');
        }

        // Feeds never need a DTD, and internal entities ARE expanded by libxml
        // (external ones are not, so XXE is already out). Rejecting doctypes
        // outright makes entity-expansion DoS impossible here, rather than relying
        // on libxml's built-in amplification limit, which varies by version.
        if ($document->doctype !== null) {
            throw new FeedParseException('Feed documents must not declare a DTD');
        }

        // Which dialect parser handles this root — including the Atom 1.0 vs 0.3
        // namespace split — is each parser's own call now; the factory returns
        // the match or raises FeedParseException when none claims the root.
        return $this->parserFactory->parserFor($root)->parse($document);
    }

    /**
     * An XML declaration is only a declaration when it starts at byte 0, so a
     * blank line a plugin echoed ahead of the feed makes libxml refuse the whole
     * document. Feeds arrive that way often enough to cost real subscriptions
     * (#423), and nothing of value can precede the declaration — so drop it.
     */
    private function fromTheDeclaration(string $xml): string
    {
        return ltrim($xml, " \t\n\r\0\x0B\u{FEFF}");
    }
}
