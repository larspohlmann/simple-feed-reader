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
        // loadXML() throws a raw ValueError on an empty string rather than
        // returning false, so guard it here — mirroring HtmlItemExtractor's
        // empty-page check. An empty 200 body (misconfigured feed, edge CDN) is
        // a per-feed parse failure the refresh runner already handles, not an
        // uncaught error that 500s the whole run and stalls every feed after it.
        if (trim($xml) === '') {
            throw new FeedParseException('Document is not well-formed XML');
        }

        $document = new \DOMDocument();
        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
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
        // outright makes entity-expansion DoS impossible here instead of
        // relying on libxml's built-in amplification limit, which varies by
        // version.
        if ($document->doctype !== null) {
            throw new FeedParseException('Feed documents must not declare a DTD');
        }

        // Which dialect parser handles this root — including the Atom 1.0 vs 0.3
        // namespace split — is each parser's own call now; the factory returns
        // the match or raises FeedParseException when none claims the root.
        return $this->parserFactory->parserFor($root)->parse($document);
    }
}
