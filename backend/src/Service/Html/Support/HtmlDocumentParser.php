<?php

declare(strict_types=1);

namespace App\Service\Html\Support;

use App\Service\Html\Exception\UnparseableHtmlException;
use Dom\HTMLDocument;

/**
 * Parses HTML into the HTML5 DOM (`\Dom\HTMLDocument`, lexbor) the reader, discovery and scraper read. It
 * resolves no entities and opens no connections, so it needs no LIBXML_NONET, which it rejects as a flag.
 */
final class HtmlDocumentParser
{
    public static function parse(string $html): HTMLDocument
    {
        return self::parsed($html)
            ?? throw new UnparseableHtmlException('The HTML is blank or could not be parsed.');
    }

    /** A document with nothing in it when the HTML is blank or unreadable: nothing to read is not a failure here. */
    public static function parseOrEmpty(string $html): HTMLDocument
    {
        return self::parsed($html) ?? HTMLDocument::createEmpty();
    }

    private static function parsed(string $html): ?HTMLDocument
    {
        if (trim($html) === '') {
            return null;
        }

        try {
            return HTMLDocument::createFromString($html, \LIBXML_NOERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    private function __construct()
    {
    }
}
