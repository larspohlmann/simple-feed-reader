<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Exception\ValidationException;

/**
 * The terms a search runs on, parsed from one raw query string.
 *
 * Every term must match for a row to qualify, so each extra term is another
 * pair of unindexable LIKE predicates — hence the ceiling on how many a single
 * query may carry. A paste past that ceiling loses its tail rather than being
 * rejected: the user still gets the search they asked for, only narrower than
 * the ceiling would allow.
 */
final readonly class SearchTerms
{
    public const int MIN_INPUT_LENGTH = 3;
    public const int MAX_INPUT_LENGTH = 100;
    public const int MAX_TERMS = 6;

    /**
     * What counts as whitespace for the mode check, the trim and the term
     * split alike — one definition, used everywhere in this class. Plain
     * `\s` is ASCII-only, but the frontend's own trailing-space detection
     * (`normalizeSearchInput` in `query.ts`) runs on JavaScript's `\s`,
     * which also matches a no-break space and the other Unicode "space
     * separator" characters a paste or an autocorrect can leave behind.
     * `\p{Z}` (the Unicode separator category) closes that gap: without it,
     * a trailing no-break space reads as whole-word on the client but as
     * substring here, and — because neither `trim()` nor a plain `\s+`
     * split would remove or split on it — the character itself survives
     * into the last term and the search silently matches nothing.
     */
    private const string WHITESPACE = '[\s\p{Z}]';

    private const string DOUBLE_QUOTE = '"';

    /** @param list<string> $terms */
    private function __construct(
        public array $terms,
        public bool $isWholeWord,
        public bool $isPhrase,
    ) {
    }

    public static function fromInput(string $input): self
    {
        $trimmed = self::stripSurroundingWhitespace($input);
        self::assertLengthIsUsable($trimmed);

        // A query wrapped in double quotes is one exact phrase -- the strongest
        // signal, so it is read before the trailing-space check and wins when both
        // are present. An empty phrase (only quotes and whitespace inside) is no
        // phrase at all and falls through to ordinary parsing.
        $phrase = self::phraseWithin($trimmed);
        if ($phrase !== null) {
            return new self([$phrase], isWholeWord: false, isPhrase: true);
        }

        // The mode is a property of the raw input, decided before trimming: a
        // trailing space is the signal, and trimming would erase it. It is one flag
        // for the whole query, not per-term -- a per-term rule would mark every term
        // but the last "whole word" just for being followed by a space while typing.
        $isWholeWord = (bool) preg_match('/' . self::WHITESPACE . '\z/u', $input);

        return self::split($trimmed, $isWholeWord);
    }

    /**
     * The same terms, for a caller that already holds the mode as its own
     * field instead of as a trailing space or wrapping quotes — a saved search
     * stores the mode apart from the bare term. Without this, such a caller
     * has to re-encode a raw query string purely so fromInput can parse the
     * mode back off it.
     */
    public static function fromTermAndMode(string $term, SearchMode $mode): self
    {
        $trimmed = self::stripSurroundingWhitespace($term);
        self::assertLengthIsUsable($trimmed);

        if ($mode->isPhrase()) {
            return new self([self::collapseWhitespace($trimmed)], isWholeWord: false, isPhrase: true);
        }

        return self::split($trimmed, $mode->isWholeWord());
    }

    private static function split(string $trimmed, bool $isWholeWord): self
    {
        /** @var list<string> $terms */
        $terms = preg_split('/' . self::WHITESPACE . '+/u', $trimmed) ?: [];

        return new self(\array_slice($terms, 0, self::MAX_TERMS), $isWholeWord, isPhrase: false);
    }

    /**
     * The phrase inside a query wrapped in double quotes, or null when the
     * query is not wrapped or wraps nothing usable. Inner quotes become
     * boundaries (a stray one would otherwise reopen a phrase) and inner
     * whitespace collapses to single spaces, so the phrase lines up with the
     * single spaces of real article text.
     */
    private static function phraseWithin(string $trimmed): ?string
    {
        if (!str_starts_with($trimmed, self::DOUBLE_QUOTE) || !str_ends_with($trimmed, self::DOUBLE_QUOTE)) {
            return null;
        }

        $inner = mb_substr($trimmed, 1, mb_strlen($trimmed) - 2);
        $phrase = self::collapseWhitespace(str_replace(self::DOUBLE_QUOTE, ' ', $inner));

        return $phrase === '' ? null : $phrase;
    }

    private static function collapseWhitespace(string $value): string
    {
        return trim((string) preg_replace('/' . self::WHITESPACE . '+/u', ' ', $value));
    }

    private static function stripSurroundingWhitespace(string $input): string
    {
        $pattern = '/\A' . self::WHITESPACE . '+|' . self::WHITESPACE . '+\z/u';

        return preg_replace($pattern, '', $input) ?? $input;
    }

    private static function assertLengthIsUsable(string $trimmed): void
    {
        if (mb_strlen($trimmed) < self::MIN_INPUT_LENGTH) {
            throw new ValidationException([
                'q' => [\sprintf('Search for at least %d characters.', self::MIN_INPUT_LENGTH)],
            ]);
        }

        if (mb_strlen($trimmed) > self::MAX_INPUT_LENGTH) {
            throw new ValidationException([
                'q' => [\sprintf('Search for at most %d characters.', self::MAX_INPUT_LENGTH)],
            ]);
        }
    }
}
