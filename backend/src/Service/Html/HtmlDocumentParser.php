<?php

declare(strict_types=1);

namespace App\Service\Html;

use Dom\HTMLDocument;

/**
 * Parses HTML into the HTML5-spec DOM (`\Dom\HTMLDocument`, lexbor) that the
 * reader pipeline, feed discovery and the scraper layers all read.
 *
 * Blank or unparseable input yields null — a page too broken to parse is an
 * answer every caller can handle (skip it, fall back) rather than a fatal. The
 * parser resolves no entities and opens no connections, so it needs no
 * LIBXML_NONET — which it rejects as an invalid flag.
 */
final class HtmlDocumentParser
{
    public static function parseOrNull(string $html): ?HTMLDocument
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
}
