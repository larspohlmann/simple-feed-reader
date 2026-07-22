<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;

final readonly class FeedParser
{
    public function __construct(
        private readonly Rss2Parser $rss2Parser,
        private readonly Atom10Parser $atom10Parser,
        private readonly Atom03Parser $atom03Parser,
        private readonly Rss1Parser $rss1Parser,
    ) {
    }

    public function parse(string $xml): ParsedFeed
    {
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

        return match ($root->localName) {
            'rss' => $this->rss2Parser->parse($document),
            'feed' => $this->atomParserFor($root)->parse($document),
            'RDF' => $this->rss1Parser->parse($document),
            default => throw new FeedParseException(
                sprintf('Unknown feed root element <%s>', (string) $root->localName),
            ),
        };
    }

    /**
     * A <feed> root is Atom, but the dialect is decided by its namespace — the
     * modern 1.0 or the legacy 0.3. Anything else is a feed we do not parse, and
     * saying so beats handing it to the wrong parser and yielding an empty feed.
     */
    private function atomParserFor(\DOMElement $root): AbstractAtomParser
    {
        return match ($root->namespaceURI) {
            Atom10Parser::NAMESPACE => $this->atom10Parser,
            Atom03Parser::NAMESPACE => $this->atom03Parser,
            default => throw new FeedParseException(
                sprintf('Unsupported Atom namespace "%s"', (string) $root->namespaceURI),
            ),
        };
    }
}
