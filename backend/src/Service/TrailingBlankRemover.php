<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Removes the blank tail a feed leaves on an article: trailing whitespace and
 * newlines, non-breaking spaces, a final line break, and blocks holding nothing
 * but those.
 *
 * Feeds really do close their bodies this way, and none of it is invisible: an
 * empty paragraph still carries the article's paragraph margin, so a body
 * ending `<p>&nbsp;</p><p></p><br>` renders 88px of blank space under the last
 * sentence (#296). A bare `&nbsp;` survives `trim()` besides — it is U+00A0,
 * which PHP does not count as whitespace.
 *
 * Deliberately textual and anchored to the very end of the string rather than a
 * DOM round-trip: re-serialising every article to delete its last empty tag
 * would rewrite markup this class has no business touching.
 */
final class TrailingBlankRemover
{
    /** What a block may hold and still count as empty. */
    private const string BLANK = '(?:\s|&nbsp;|&#0*160;|&#x0*a0;|\x{00A0}|<br\b[^>]*>)';

    /** Blocks that draw nothing of their own, so an empty one is pure tail. */
    private const string EMPTY_BLOCK =
        '<(p|div|span|section|article|figcaption|li)\b[^>]*>' . self::BLANK . '*<\/\1\s*>';

    /**
     * Nothing but blanks and closing tags between here and the end of the
     * string. An empty block nested at the end — `…<div><p></p></div>` — is as
     * much the tail as one at the top level, and stripping it leaves its parent
     * empty for the next pass to take.
     */
    private const string ONLY_TAIL_AFTER = '(?=(?:' . self::BLANK . '|<\/[a-z]+\s*>)*$)';

    /**
     * Applied in order until a whole pass changes nothing. Every pattern
     * strictly shortens its input, so the loop terminates.
     */
    private const array BLANK_TAIL = [
        '/\s+$/u',
        '/(?:&nbsp;|&#0*160;|&#x0*a0;|\x{00A0})+$/iu',
        '/<br\b[^>]*>$/iu',
        '/' . self::EMPTY_BLOCK . self::ONLY_TAIL_AFTER . '/iu',
    ];

    public function removeFrom(string $html): string
    {
        do {
            $shorter = $this->stripOnce($html);
            if ($shorter === null) {
                return $html;
            }
            $changed = $shorter !== $html;
            $html = $shorter;
        } while ($changed);

        return $html;
    }

    /**
     * One pass of every pattern, or null when the input is not valid UTF-8 —
     * `preg_replace` answers null there, and taking that for an empty result
     * would delete the article instead of its tail.
     */
    private function stripOnce(string $html): ?string
    {
        $stripped = $html;
        foreach (self::BLANK_TAIL as $pattern) {
            $shorter = preg_replace($pattern, '', $stripped);
            if ($shorter === null) {
                return null;
            }
            $stripped = $shorter;
        }

        return $stripped;
    }
}
