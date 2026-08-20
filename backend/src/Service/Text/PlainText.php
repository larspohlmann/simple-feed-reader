<?php

declare(strict_types=1);

namespace App\Service\Text;

/**
 * Reduces a possibly-HTML string to display-ready plain text: strips markup and
 * decodes HTML entities, then collapses runs of whitespace. Returns null when
 * nothing printable remains, so a caller can fall back (e.g. to '(untitled)').
 *
 * Feed <title> elements routinely carry entity-escaped HTML — "&lt;em&gt;" for
 * emphasis, "&amp;#8220;" for a curly quote — which the XML reader decodes one
 * level to a literal "<em>" tag or "&#8220;" reference. Rendered as text, that
 * markup leaks into the UI; this collapses it to the words a reader wants.
 *
 * The result is PLAIN TEXT: it may contain <, > and & as literal characters and
 * has NOT been through EntrySanitizer. Render it as text only, never with |raw.
 */
final class PlainText
{
    /**
     * Elements whose boundaries must survive as whitespace once tags are
     * stripped — used by fromHtmlBlocks(), never by from() itself, because
     * from() also runs over already-plain-text-ish sources (feed titles) where
     * inventing word breaks would be wrong.
     */
    private const string BLOCK_BOUNDARY_PATTERN = '/<\/?(?:p|div|br|li|ul|ol|h[1-6]|tr|td|th|table|thead|tbody'
        . '|blockquote|section|article|header|footer|aside|nav|figure|figcaption|dd|dt|dl)\b[^>]*>/i';

    public static function from(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $decoded = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5);
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $decoded));

        return $collapsed === '' ? null : $collapsed;
    }

    /**
     * Same reduction as from(), but for HTML known to carry block-level
     * structure (an entry body, never a feed <title>): strip_tags() alone
     * concatenates text across element boundaries with no separator, so
     * "<p>one</p><p>two</p>" would otherwise read as the single word "onetwo".
     * Block-level tags are turned into whitespace first so the words on either
     * side of a paragraph, list item or table cell stay separate.
     */
    public static function fromHtmlBlocks(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $withBoundaries = preg_replace(self::BLOCK_BOUNDARY_PATTERN, ' ', $html) ?? $html;

        return self::from($withBoundaries);
    }
}
