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
        return self::parsed($html, null)
            ?? throw new UnparseableHtmlException('The HTML is blank or could not be parsed.');
    }

    /** A body fragment, UTF-8 already whatever a `<meta charset>` inside it declares; empty when unreadable. */
    public static function parseFragment(string $html): HTMLDocument
    {
        return self::parsed('<body>' . $html, 'UTF-8') ?? HTMLDocument::createEmpty();
    }

    /** A document with nothing in it when the HTML is blank or unreadable: nothing to read is not a failure here. */
    public static function parseOrEmpty(string $html): HTMLDocument
    {
        return self::parsed($html, null) ?? HTMLDocument::createEmpty();
    }

    private static function parsed(string $html, ?string $encoding): ?HTMLDocument
    {
        if (trim($html) === '') {
            return null;
        }

        try {
            return HTMLDocument::createFromString($html, \LIBXML_NOERROR, $encoding);
        } catch (\Throwable) {
            return null;
        }
    }

    private function __construct()
    {
    }
}
