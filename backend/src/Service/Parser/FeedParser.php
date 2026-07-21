<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;

final class FeedParser
{
    public function __construct(
        private readonly Rss2Parser $rss2Parser,
        private readonly AtomParser $atomParser,
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

        return match ($root->localName) {
            'rss' => $this->rss2Parser->parse($document),
            'feed' => $this->atomParser->parse($document),
            'RDF' => $this->rss1Parser->parse($document),
            default => throw new FeedParseException(
                sprintf('Unknown feed root element <%s>', (string) $root->localName),
            ),
        };
    }
}
