<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\Factory\FeedParserFactory;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Parser\Pass\StreamedFeedDocument;

final readonly class FeedParser
{
    public function __construct(
        private FeedParserFactory $parserFactory,
    ) {
    }

    public function parse(string $xml): ParsedFeedModel
    {
        $feedXml = $this->fromTheDeclaration($this->withoutIllegalControlCharacters($xml));

        // XMLReader::fromString('') throws a ValueError that would 500 the whole refresh run, so an empty body (a
        // BOM-only one included, once stripped) must fail here as a per-feed parse error.
        if ($feedXml === '') {
            throw new FeedParseException('Document is not well-formed XML');
        }

        $previousErrorMode = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = StreamedFeedDocument::open($feedXml);

            return $document->parseWith($this->parserFactory->parserFor($document->root()));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
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
