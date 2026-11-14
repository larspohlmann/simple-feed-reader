<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Pass;

use App\Service\Social\Social\Model\SearchExpression\AllOfSearchModel;
use App\Service\Social\Social\Model\SearchExpression\AnyOfSearchModel;
use App\Service\Social\Social\Model\SearchExpression\AuthorSearchModel;
use App\Service\Social\Social\Model\SearchExpression\NotSearchModel;
use App\Service\Social\Social\Model\SearchExpression\SearchExpressionModelInterface;
use App\Service\Social\Social\Model\SearchExpression\SearchTermModel;
use App\Service\Social\Social\Model\SearchExpression\UncheckedSearchModel;
use App\Service\Social\Social\Support\SearchQueryTokens;

/**
 * One query read as `||` over runs of ANDed terms, `-` and `( )`; the network has validated it already, so odd input
 * degrades instead of failing.
 */
final class SearchQueryParser
{
    private const string NETWORK_FILTER_PATTERN = '/^\p{L}+:./u';
    // `from:me` names the searching account, which the reader cannot tell from an author called "me".
    private const string AUTHOR_FILTER_PATTERN = '/^from:@?(?!me$)(?<author>[^@].*)$/iu';

    private int $position = 0;

    private int $groupDepth = 0;

    /** @param list<array{string, string}> $tokens kind and text */
    public function __construct(private readonly array $tokens)
    {
    }

    public function expression(): SearchExpressionModelInterface
    {
        return $this->alternatives() ?? new UncheckedSearchModel();
    }

    private function alternatives(): ?SearchExpressionModelInterface
    {
        $alternatives = [$this->conjunction()];
        while ($this->accepts(SearchQueryTokens::OR)) {
            $alternatives[] = $this->conjunction();
        }

        return self::anyOf(array_values(array_filter($alternatives)));
    }

    private function conjunction(): ?SearchExpressionModelInterface
    {
        $factors = [];
        while (null !== $kind = $this->tokens[$this->position][0] ?? null) {
            if (SearchQueryTokens::OR === $kind || (SearchQueryTokens::CLOSE === $kind && $this->groupDepth > 0)) {
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
            SearchQueryTokens::NOT => self::negated($this->factor()),
            SearchQueryTokens::OPEN => $this->group(),
            SearchQueryTokens::PHRASE => self::termOf($text),
            SearchQueryTokens::WORD => self::wordOf($text),
            default => null,
        };
    }

    private function group(): ?SearchExpressionModelInterface
    {
        ++$this->groupDepth;
        $alternatives = $this->alternatives();
        $this->accepts(SearchQueryTokens::CLOSE);
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
