<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Service\Text\PlainText;

/**
 * Produces PLAIN TEXT, not HTML, from an entry body.
 *
 * Images are stripped first: the image is its own column now, so an <img>
 * adds nothing, and a thumbnail wrapped in an anchor would otherwise leave the
 * anchor's whitespace behind.
 *
 * Block boundaries (<p>, <br>, <li>, ...) become whitespace before tags are
 * stripped (see PlainText::fromHtmlBlocks(), which this delegates to), since
 * strip_tags() otherwise concatenates across them: "<p>one</p><p>two</p>"
 * would read as "onetwo".
 *
 * A body that reduces to nothing, or to a single junk token — how DIE ZEIT's
 * CMS leaks a Python `None` into content:encoded — yields null, so the caller
 * routes the entry to a title-led block.
 *
 * The result may contain <, > and & as literal characters and has NOT been
 * through EntrySanitizer. Render as text only, never with |raw or innerHTML.
 */
final class EntrySnippet
{
    private const int MAX_LENGTH = 500;

    /** Single-token bodies that carry no meaning. Matched only when they are the ENTIRE body. */
    private const array JUNK = ['none', 'null', 'nil', 'n/a', '-', '—'];

    public static function from(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return null;
        }

        $withoutImages = preg_replace('/<img\b[^>]*>/i', ' ', $html) ?? $html;
        $text = PlainText::fromHtmlBlocks($withoutImages);

        if ($text === null || \in_array(mb_strtolower($text), self::JUNK, true)) {
            return null;
        }

        return mb_substr($text, 0, self::MAX_LENGTH);
    }
}
