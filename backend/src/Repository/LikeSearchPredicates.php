<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\WordBoundaries;
use App\Service\Search\Model\SearchTermsModel;
use App\Service\Search\Support\LikePattern;
use Doctrine\ORM\QueryBuilder;

/** The search as LIKEs, for MySQL: its utf8mb4_0900_ai_ci columns already ignore case and accents. */
final readonly class LikeSearchPredicates
{
    public function build(QueryBuilder $qb, SearchTermsModel $terms, string $prefix, string $entryAlias): string
    {
        $predicates = [];
        foreach ($terms->terms as $position => $term) {
            $parameter = $prefix . $position;
            $columnMatch = $terms->isWholeWord
                ? $this->wholeWordMatch($qb, $parameter, $term)
                : $this->substringMatch($qb, $parameter, $term);
            $predicates[] = SearchedColumns::anyOf($columnMatch, $parameter, $entryAlias);
        }

        return '(' . implode(' AND ', $predicates) . ')';
    }

    /** @return \Closure(string): string */
    private function substringMatch(QueryBuilder $qb, string $parameter, string $term): \Closure
    {
        $qb->setParameter($parameter, LikePattern::containing($term));

        return static fn (string $column): string => \sprintf(
            "%s LIKE :%s ESCAPE '%s'",
            $column,
            $parameter,
            LikePattern::ESCAPE_CHARACTER,
        );
    }

    /**
     * A cheap "%term%" LIKE runs first and rejects most rows before the REPLACE chain. It is sound only while the raw
     * term is a substring of every row the normalized check accepts, so a term with boundary punctuation skips it:
     * "E-Mail" must match "E–Mail", which does not contain it.
     *
     * @return \Closure(string): string
     */
    private function wholeWordMatch(QueryBuilder $qb, string $parameter, string $term): \Closure
    {
        $word = $parameter . 'Word';
        $cheap = WordBoundaries::areIn($term) ? null : $parameter . 'Cheap';

        $qb->setParameter($word, LikePattern::wholeWord($term));
        if ($cheap !== null) {
            $qb->setParameter($cheap, LikePattern::containing($term));
        }

        return static fn (string $column): string => self::wholeWordColumnMatch($column, $cheap, $word);
    }

    private static function wholeWordColumnMatch(string $column, ?string $cheap, string $word): string
    {
        $escape = LikePattern::ESCAPE_CHARACTER;
        $normalized = \sprintf(
            "CONCAT(' ', NORMALIZE_WORD_BOUNDARIES(%s), ' ') LIKE :%s ESCAPE '%s'",
            $column,
            $word,
            $escape,
        );

        if ($cheap === null) {
            return '(' . $normalized . ')';
        }

        return \sprintf("(%s LIKE :%s ESCAPE '%s' AND %s)", $column, $cheap, $escape, $normalized);
    }
}
