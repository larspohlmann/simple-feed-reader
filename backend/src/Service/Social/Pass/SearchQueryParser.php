<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Service\Social\Model\SearchExpression\AllOfSearchModel;
use App\Service\Social\Model\SearchExpression\AnyOfSearchModel;
use App\Service\Social\Model\SearchExpression\NotSearchModel;
use App\Service\Social\Model\SearchExpression\SearchExpressionModelInterface;
use App\Service\Social\Model\SearchExpression\SearchTermModel;
use App\Service\Social\Model\SearchExpression\UncheckedSearchModel;

/**
 * One query read as `||` over runs of ANDed terms, `-` and `( )`; the network has validated it already, so odd input
 * degrades instead of failing. A list of operands holds one expression, or none where nothing checkable was written.
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
        return $this->alternatives()[0] ?? new UncheckedSearchModel();
    }

    /** @return list<SearchExpressionModelInterface> */
    private function alternatives(): array
    {
        $alternatives = [$this->conjunction()];
        while ($this->accepts(self::OR)) {
            $alternatives[] = $this->conjunction();
        }

        return self::anyOf(array_merge(...$alternatives));
    }

    /** @return list<SearchExpressionModelInterface> */
    private function conjunction(): array
    {
        $factors = [];
        while (null !== $kind = $this->tokens[$this->position][0] ?? null) {
            if (self::OR === $kind || (self::CLOSE === $kind && $this->groupDepth > 0)) {
                break;
            }
            array_push($factors, ...$this->factor());
        }

        return self::allOf($factors);
    }

    /** @return list<SearchExpressionModelInterface> */
    private function factor(): array
    {
        [$kind, $text] = $this->tokens[$this->position++] ?? ['', ''];

        return match ($kind) {
            self::NOT => self::negated($this->factor()),
            self::OPEN => $this->group(),
            self::PHRASE => self::termOf($text),
            self::WORD => self::wordOf($text),
            default => [],
        };
    }

    /** @return list<SearchExpressionModelInterface> */
    private function group(): array
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

    /** @return list<SearchExpressionModelInterface> */
    private static function wordOf(string $text): array
    {
        return 1 === preg_match(self::NETWORK_FILTER_PATTERN, $text)
            ? [new UncheckedSearchModel()]
            : self::termOf($text);
    }

    /** @return list<SearchExpressionModelInterface> */
    private static function termOf(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $words);

        return [] === $words[0] ? [] : [new SearchTermModel($words[0])];
    }

    /**
     * @param list<SearchExpressionModelInterface> $operand
     *
     * @return list<SearchExpressionModelInterface>
     */
    private static function negated(array $operand): array
    {
        return array_map(
            static fn (SearchExpressionModelInterface $expression): SearchExpressionModelInterface
                => $expression instanceof UncheckedSearchModel ? $expression : new NotSearchModel($expression),
            $operand,
        );
    }

    /**
     * @param list<SearchExpressionModelInterface> $factors
     *
     * @return list<SearchExpressionModelInterface>
     */
    private static function allOf(array $factors): array
    {
        $checked = array_values(array_filter(
            $factors,
            static fn (SearchExpressionModelInterface $factor): bool => !$factor instanceof UncheckedSearchModel,
        ));

        return match (true) {
            [] === $checked => \array_slice($factors, 0, 1),
            1 === \count($checked) => $checked,
            default => [new AllOfSearchModel($checked)],
        };
    }

    /**
     * @param list<SearchExpressionModelInterface> $alternatives
     *
     * @return list<SearchExpressionModelInterface>
     */
    private static function anyOf(array $alternatives): array
    {
        foreach ($alternatives as $alternative) {
            if ($alternative instanceof UncheckedSearchModel) {
                return [$alternative];
            }
        }

        return \count($alternatives) > 1 ? [new AnyOfSearchModel($alternatives)] : $alternatives;
    }
}
