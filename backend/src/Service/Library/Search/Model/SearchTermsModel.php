<?php

declare(strict_types=1);

namespace App\Service\Search\Model;

use App\Exception\ValidationException;
use App\Service\Text\Support\Whitespace;

/**
 * The terms a search runs on, parsed from one raw query. Each term adds two unindexable LIKE predicates, hence the
 * ceiling; a paste past it loses its tail rather than being rejected.
 */
final readonly class SearchTermsModel
{
    public const int MIN_INPUT_LENGTH = 3;
    public const int MAX_INPUT_LENGTH = 100;
    public const int MAX_TERMS = 6;

    /**
     * Whitespace for the mode check, trim and split alike. `\p{Z}` matches what JavaScript's `\s` does in the client's
     * normalizeSearchInput: a trailing no-break space must mean whole-word here too, not a stray character in a term.
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

        // Wrapping quotes are the strongest signal, so the phrase is read first; an empty phrase is no phrase.
        $phrase = self::phraseWithin($trimmed);
        if ($phrase !== null) {
            return new self([$phrase], isWholeWord: false, isPhrase: true);
        }

        // Read before trim() erases the trailing space, and one flag for the whole query, not per term.
        $isWholeWord = (bool) preg_match('/' . self::WHITESPACE . '\z/u', $input);

        return self::split($trimmed, $isWholeWord);
    }

    /** The same terms for a caller that stores the mode apart from the bare term, as a saved search does. */
    public static function fromTermAndMode(string $term, SearchMode $mode): self
    {
        $trimmed = self::stripSurroundingWhitespace($term);
        self::assertLengthIsUsable($trimmed);

        if ($mode->isPhrase()) {
            return new self([Whitespace::collapse($trimmed)], isWholeWord: false, isPhrase: true);
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
     * The phrase inside wrapping double quotes, or null when there is none usable. Inner quotes become boundaries and
     * inner whitespace collapses to single spaces, to line up with real article text.
     */
    private static function phraseWithin(string $trimmed): ?string
    {
        if (!str_starts_with($trimmed, self::DOUBLE_QUOTE) || !str_ends_with($trimmed, self::DOUBLE_QUOTE)) {
            return null;
        }

        $inner = mb_substr($trimmed, 1, mb_strlen($trimmed) - 2);
        $phrase = Whitespace::collapse(str_replace(self::DOUBLE_QUOTE, ' ', $inner));

        return $phrase === '' ? null : $phrase;
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
