<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Produces PLAIN TEXT, not HTML, from an entry body.
 *
 * Images are removed before the text is extracted: now that the image is its
 * own column, an <img> contributes nothing to a snippet, and feeds that wrap a
 * thumbnail in an anchor would otherwise leave the anchor's whitespace behind.
 *
 * Block-level boundaries (<p>, <br>, <li>, ...) are turned into whitespace
 * before tags are stripped, because strip_tags() otherwise concatenates text
 * across them with no separator — "<p>one</p><p>two</p>" would read as the
 * single word "onetwo".
 *
 * A body that reduces to nothing — or to a single junk token, which is how DIE
 * ZEIT's CMS leaks a Python `None` into content:encoded — yields null rather
 * than a snippet, so the caller can route the entry to a title-led block.
 *
 * The result may contain <, > and & as literal characters and has NOT been
 * through EntrySanitizer. Render it as text only; never with |raw or innerHTML.
 */
final class EntrySnippet
{
    private const int MAX_LENGTH = 500;

    /** Single-token bodies that carry no meaning. Matched only when they are the ENTIRE body. */
    private const array JUNK = ['none', 'null', 'nil', 'n/a', '-', '—'];

    /** Elements whose boundaries must survive as whitespace once tags are stripped. */
    private const string BLOCK_BOUNDARY_PATTERN = '/<\/?(?:p|div|br|li|ul|ol|h[1-6]|tr|td|th|table|thead|tbody'
        . '|blockquote|section|article|header|footer|aside|nav|figure|figcaption|dd|dt|dl)\b[^>]*>/i';

    public static function from(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return null;
        }

        $withoutImages = preg_replace('/<img\b[^>]*>/i', ' ', $html) ?? $html;
        $withBlockBoundariesAsSpace = preg_replace(self::BLOCK_BOUNDARY_PATTERN, ' ', $withoutImages)
            ?? $withoutImages;
        $text = trim(html_entity_decode(strip_tags($withBlockBoundariesAsSpace), ENT_QUOTES | ENT_HTML5));
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '' || \in_array(mb_strtolower($text), self::JUNK, true)) {
            return null;
        }

        return mb_substr($text, 0, self::MAX_LENGTH);
    }
}
