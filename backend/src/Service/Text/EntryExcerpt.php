<?php

declare(strict_types=1);

namespace App\Service\Text;

/**
 * A plain-text preview for an entry list row: the summary's text, or the
 * body's, cut to the first MAX_LENGTH characters at a word boundary.
 *
 * JUNK is the union of the frontend's leaked-null tokens (preview-image.ts's
 * `none|null|undefined`) and the server's ingest-time ones
 * (EntrySnippet::JUNK's `none|null|nil|n/a|-|—`): either source can hand this
 * a single meaningless token as an entire field.
 */
final class EntryExcerpt
{
    private const int MAX_LENGTH = 500;

    private const array JUNK = ['none', 'null', 'undefined', 'nil', 'n/a', '-', '—'];

    public static function of(?string $summary, ?string $contentHtml): string
    {
        return self::plainText($summary) ?? self::plainText($contentHtml) ?? '';
    }

    private static function plainText(?string $html): ?string
    {
        $text = PlainText::fromHtmlBlocks(self::withoutImages($html));
        if ($text === null || \in_array(mb_strtolower($text), self::JUNK, true)) {
            return null;
        }

        return self::cutAtWordBoundary($text);
    }

    /**
     * An <img> is not a block boundary, so PlainText::fromHtmlBlocks() would
     * otherwise merge the words on either side of it into one, the same
     * reason EntrySnippet::from() strips images before that call.
     */
    private static function withoutImages(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        return preg_replace('/<img\b[^>]*>/i', ' ', $html) ?? $html;
    }

    private static function cutAtWordBoundary(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::MAX_LENGTH);
        $lastSpace = mb_strrpos($cut, ' ');

        return $lastSpace === false ? $cut : mb_substr($cut, 0, $lastSpace);
    }
}
