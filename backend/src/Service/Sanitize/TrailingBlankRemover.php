<?php

declare(strict_types=1);

namespace App\Service\Sanitize;

/**
 * Strips an article's blank tail: whitespace, non-breaking spaces (U+00A0 survives trim()), a final <br> and empty
 * blocks, which still draw their margin. Textual and anchored to the end, so no other markup is re-serialised.
 */
final readonly class TrailingBlankRemover
{
    /** What a block may hold and still count as empty. */
    private const string BLANK = '(?:\s|&nbsp;|&#0*160;|&#x0*a0;|\x{00A0}|<br\b[^>]*>)';

    /** Blocks that draw nothing of their own, so an empty one is pure tail. */
    private const string EMPTY_BLOCK =
        '<(p|div|span|section|article|figcaption|li)\b[^>]*>' . self::BLANK . '*<\/\1\s*>';

    /** Only blanks and closing tags follow: a nested empty block is tail too, and its emptied parent goes next pass. */
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
