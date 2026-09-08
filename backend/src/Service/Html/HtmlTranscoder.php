<?php

declare(strict_types=1);

namespace App\Service\Html;

use Dom\HTMLDocument;

/**
 * Re-encodes a page whose charset only its HTTP Content-Type declared as
 * self-describing UTF-8, once, at the fetch boundary (#904). The HTML5 parser
 * sees only the bytes, so it cannot honour the header — and it trusts a stale
 * <meta charset> over UTF-8 bytes, so the declaration is rewritten as well.
 */
final class HtmlTranscoder
{
    private const array UTF8_LABELS = ['utf-8', 'utf8'];
    private const string META_CHARSET = '/charset\s*=\s*"?[^";\s]+"?/i';

    /** Unchanged for UTF-8 and for a label the parser does not know: the raw bytes still parse on their own. */
    public static function toUtf8(string $html, string $charset): string
    {
        if (in_array(strtolower($charset), self::UTF8_LABELS, true)) {
            return $html;
        }

        try {
            $document = HTMLDocument::createFromString($html, \LIBXML_NOERROR, $charset);
        } catch (\Throwable) {
            return $html;
        }

        $document->charset = 'UTF-8';
        self::declareUtf8In($document);

        return $document->saveHtml();
    }

    private static function declareUtf8In(HTMLDocument $document): void
    {
        foreach ($document->querySelectorAll('meta[charset]') as $meta) {
            $meta->setAttribute('charset', 'utf-8');
        }
        foreach ($document->querySelectorAll('meta[http-equiv="content-type" i]') as $meta) {
            $content = $meta->getAttribute('content') ?? '';
            $declared = preg_replace(self::META_CHARSET, 'charset=utf-8', $content) ?? $content;
            $meta->setAttribute('content', $declared);
        }
    }
}
