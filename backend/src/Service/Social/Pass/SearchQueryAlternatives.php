<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Service\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Support\SearchQueryTokens;

/**
 * One query spelled out as the ANDed queries it is the `||` of, for a network without `||`. A negated group is left
 * out: the network then finds more, never less, and the reader's own re-check drops the rest.
 */
final class SearchQueryAlternatives
{
    public const int MAX_ALTERNATIVES = 8;

    private int $position = 0;

    private int $groupDepth = 0;

    /** @param list<array{string, string}> $tokens kind and text */
    public function __construct(private readonly array $tokens)
    {
    }

    /**
     * @return non-empty-list<string>
     *
     * @throws SearchQueryTooBroadException
     */
    public function queries(): array
    {
        $queries = array_values(array_unique(array_map(
            static fn (array $factors): string => implode(' ', array_unique($factors)),
            $this->alternatives(),
        )));

        return [] === $queries ? [''] : $queries;
    }

    /** @return list<list<string>> */
    private function alternatives(): array
    {
        $alternatives = $this->conjunction();
        while ($this->accepts(SearchQueryTokens::OR)) {
            $alternatives = self::capped([...$alternatives, ...$this->conjunction()]);
        }

        return $alternatives;
    }

    /** @return list<list<string>> */
    private function conjunction(): array
    {
        $alternatives = [[]];
        while (null !== $kind = $this->tokens[$this->position][0] ?? null) {
            if (SearchQueryTokens::OR === $kind || (SearchQueryTokens::CLOSE === $kind && $this->groupDepth > 0)) {
                break;
            }
            $alternatives = self::crossed($alternatives, $this->factor());
        }

        return $alternatives;
    }

    /** @return list<list<string>> */
    private function factor(): array
    {
        [$kind, $text] = $this->tokens[$this->position++] ?? ['', ''];

        return match ($kind) {
            SearchQueryTokens::NOT => self::negated($this->factor()),
            SearchQueryTokens::OPEN => $this->group(),
            SearchQueryTokens::PHRASE => '' === trim($text) ? [[]] : [['"' . $text . '"']],
            SearchQueryTokens::WORD => [[$text]],
            default => [[]],
        };
    }

    /** @return list<list<string>> */
    private function group(): array
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

    /**
     * @param list<list<string>> $operand
     *
     * @return list<list<string>>
     */
    private static function negated(array $operand): array
    {
        return 1 === \count($operand) && 1 === \count($operand[0]) ? [['-' . $operand[0][0]]] : [[]];
    }

    /**
     * @param list<list<string>> $left
     * @param list<list<string>> $right
     *
     * @return list<list<string>>
     */
    private static function crossed(array $left, array $right): array
    {
        $crossed = [];
        foreach ($left as $leftFactors) {
            foreach ($right as $rightFactors) {
                $crossed[] = [...$leftFactors, ...$rightFactors];
            }
        }

        return self::capped($crossed);
    }

    /**
     * @param list<list<string>> $alternatives
     *
     * @return list<list<string>>
     */
    private static function capped(array $alternatives): array
    {
        if (\count($alternatives) > self::MAX_ALTERNATIVES) {
            throw new SearchQueryTooBroadException(sprintf(
                'Mastodon searches each alternative on its own; this query spells out more than %d.',
                self::MAX_ALTERNATIVES,
            ));
        }

        return $alternatives;
    }
}
