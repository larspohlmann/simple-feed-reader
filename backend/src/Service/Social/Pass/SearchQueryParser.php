<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Service\Social\Model\SearchExpression\AllOfSearchModel;
use App\Service\Social\Model\SearchExpression\AnyOfSearchModel;
use App\Service\Social\Model\SearchExpression\AuthorSearchModel;
use App\Service\Social\Model\SearchExpression\NotSearchModel;
use App\Service\Social\Model\SearchExpression\SearchExpressionModelInterface;
use App\Service\Social\Model\SearchExpression\SearchTermModel;
use App\Service\Social\Model\SearchExpression\UncheckedSearchModel;

/**
 * One query read as `||` over runs of ANDed terms, `-` and `( )`; the network has validated it already, so odd input
 * degrades instead of failing.
 */
final class SearchQueryParser
{
    private const string OR = 'or';
    private const string OPEN = 'open';
    private const string CLOSE = 'close';
    private const string PHRASE = 'phrase';
    private const string NOT = 'not';
    private const string WORD = 'word';
    private const string TOKEN_PATTERN = '/(?<or>\|\|)|(?<open>\()|(?<close>\))|"(?<phrase>[^"]*)"?'
        . '|(?<not>-)(?=[^\s|)])|(?<word>[^\s()"|]+)|\S/u';
    private const string NETWORK_FILTER_PATTERN = '/^\p{L}+:./u';
    // `from:me` names the searching account, which the reader cannot tell from an author called "me".
    private const string AUTHOR_FILTER_PATTERN = '/^from:@?(?!me$)(?<author>[^@].*)$/iu';

    /** @var list<array{string, string}> kind and text */
    private readonly array $tokens;

    private int $position = 0;

    private int $groupDepth = 0;

    public function __construct(string $query)
    {
        preg_match_all(self::TOKEN_PATTERN, $query, $matches, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);
        $this->tokens = self::tokensOf($matches);
    }

    public function expression(): SearchExpressionModelInterface
    {
        return $this->alternatives() ?? new UncheckedSearchModel();
    }

    private function alternatives(): ?SearchExpressionModelInterface
    {
        $alternatives = [$this->conjunction()];
        while ($this->accepts(self::OR)) {
            $alternatives[] = $this->conjunction();
        }

        return self::anyOf(array_values(array_filter($alternatives)));
    }

    private function conjunction(): ?SearchExpressionModelInterface
    {
        $factors = [];
        while (null !== $kind = $this->tokens[$this->position][0] ?? null) {
            if (self::OR === $kind || (self::CLOSE === $kind && $this->groupDepth > 0)) {
                break;
            }
            $factors[] = $this->factor();
        }

        return self::allOf(array_values(array_filter($factors)));
    }

    private function factor(): ?SearchExpressionModelInterface
    {
        [$kind, $text] = $this->tokens[$this->position++] ?? ['', ''];

        return match ($kind) {
            self::NOT => self::negated($this->factor()),
            self::OPEN => $this->group(),
            self::PHRASE => self::termOf($text),
            self::WORD => self::wordOf($text),
            default => null,
        };
    }

    private function group(): ?SearchExpressionModelInterface
    {
        ++$this->groupDepth;
        $alternatives = $this->alternatives();
        $this->accepts(self::CLOSE);
        --$this->groupDepth;

        return $alternatives;
    }

    private function accepts(string $kind): bool
    {
        if ($kind !== ($this->tokens[$this->position][0] ?? null)) {
            return false;
        }
        ++$this->position;

        return true;
    }

    /**
     * @param list<array<int|string, string|null>> $matches
     *
     * @return list<array{string, string}>
     */
    private static function tokensOf(array $matches): array
    {
        $tokens = [];
        foreach ($matches as $match) {
            foreach ([self::OR, self::OPEN, self::CLOSE, self::PHRASE, self::NOT, self::WORD] as $kind) {
                if (null !== ($match[$kind] ?? null)) {
                    $tokens[] = [$kind, $match[$kind]];
                    break;
                }
            }
        }

        return $tokens;
    }

    private static function wordOf(string $text): ?SearchExpressionModelInterface
    {
        return match (true) {
            1 === preg_match(self::AUTHOR_FILTER_PATTERN, $text, $filter) => new AuthorSearchModel($filter['author']),
            1 === preg_match(self::NETWORK_FILTER_PATTERN, $text) => new UncheckedSearchModel(),
            default => self::termOf($text),
        };
    }

    private static function termOf(string $text): ?SearchExpressionModelInterface
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $words);

        return [] === $words[0] ? null : new SearchTermModel($words[0]);
    }

    private static function negated(?SearchExpressionModelInterface $operand): ?SearchExpressionModelInterface
    {
        return match (true) {
            null === $operand => null,
            $operand->isFullyChecked() => new NotSearchModel($operand),
            default => new UncheckedSearchModel(),
        };
    }

    /** @param list<SearchExpressionModelInterface> $factors */
    private static function allOf(array $factors): ?SearchExpressionModelInterface
    {
        return \count($factors) > 1 ? new AllOfSearchModel($factors) : $factors[0] ?? null;
    }

    /** @param list<SearchExpressionModelInterface> $alternatives */
    private static function anyOf(array $alternatives): ?SearchExpressionModelInterface
    {
        return \count($alternatives) > 1 ? new AnyOfSearchModel($alternatives) : $alternatives[0] ?? null;
    }
}
