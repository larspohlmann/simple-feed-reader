<?php

declare(strict_types=1);

namespace App\Service\Text;

/**
 * Strips <img> (see EntrySnippet for why), reduces to plain text, and rejects
 * a body that is nothing but a single junk token. Untruncated: callers cut to
 * their own length after this.
 */
final class EntryPlainText
{
    private const array JUNK = ['none', 'null', 'undefined', 'nil', 'n/a', '-', '—'];

    public static function of(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return null;
        }

        $withoutImages = preg_replace('/<img\b[^>]*>/i', ' ', $html) ?? $html;
        $text = PlainText::fromHtmlBlocks($withoutImages);

        if ($text === null || \in_array(mb_strtolower($text), self::JUNK, true)) {
            return null;
        }

        return $text;
    }
}
