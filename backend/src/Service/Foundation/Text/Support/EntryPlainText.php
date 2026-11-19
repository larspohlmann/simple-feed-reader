<?php

declare(strict_types=1);

namespace App\Service\Foundation\Text\Support;

/**
 * Reduces HTML to plain text, rejecting a body that is only a junk token. <img>
 * is replaced with a space first, so an inline image between two words becomes a
 * boundary, not a merge; untruncated — callers cut to their own length.
 */
final class EntryPlainText
{
    private const array JUNK = ['none', 'null', 'undefined', 'nil', 'n/a', '-', '—'];

    public static function fromHtml(?string $html): ?string
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

    /** The plain text cut at a word boundary to at most $maxLength characters; '' when there is none. */
    public static function cut(?string $html, int $maxLength): string
    {
        return WordBoundary::cut(self::fromHtml($html) ?? '', $maxLength);
    }

    private function __construct()
    {
    }
}
