<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\Factory\FeedParserFactory;
use App\Service\Parser\Model\ParsedFeedModel;

final readonly class FeedParser
{
    public function __construct(
        private FeedParserFactory $parserFactory,
    ) {
    }

    public function parse(string $xml): ParsedFeedModel
    {
        $feedXml = $this->fromTheDeclaration($this->withoutIllegalControlCharacters($xml));

        // loadXML('') throws a ValueError that would 500 the whole refresh run, so an empty body (a BOM-only one
        // included, once stripped) must fail here as a per-feed parse error.
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

        // Feeds never need a DTD. Rejecting any doctype keeps a declared entity from ever being expanded by the
        // dialect parsers, instead of relying on libxml's amplification limit, which varies by version.
        if ($document->doctype !== null) {
            throw new FeedParseException('Feed documents must not declare a DTD');
        }

        return $this->parserFactory->parserFor($root)->parse($document);
    }

    /** An XML declaration only counts at byte 0, and nothing of value can precede it, so leading blanks go. */
    private function fromTheDeclaration(string $xml): string
    {
        return ltrim($xml, " \t\n\r\0\x0B\u{FEFF}");
    }

    /**
     * XML 1.0 forbids the C0 controls but tab, LF and CR, so one stray byte makes libxml refuse the feed. No UTF-8
     * continuation byte falls in this range, so dropping them byte-wise is lossless.
     */
    private function withoutIllegalControlCharacters(string $xml): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $xml);
    }
}
