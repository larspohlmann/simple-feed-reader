<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\WordBoundaries;
use App\Service\Search\Model\SearchTermsModel;
use App\Service\Search\Support\LikePattern;
use Doctrine\ORM\QueryBuilder;

/**
 * One search's terms compiled into a title/summary LIKE predicate, and bound
 * onto the QueryBuilder it is handed. Needs no repository state of its own.
 */
final readonly class SearchTermsPredicateBuilder
{
    /**
     * The terms as one ANDed expression the caller places, since DatabaseSavedSearchMatcher ORs several of them.
     * $prefix keys the bound parameters, so two searches sharing a word cannot overwrite each other's value.
     */
    public function build(QueryBuilder $qb, SearchTermsModel $terms, string $prefix, string $entryAlias = 'e'): string
    {
        $predicates = [];
        foreach ($terms->terms as $position => $term) {
            $parameter = $prefix . $position;
            $predicates[] = $terms->isWholeWord
                ? $this->wholeWordPredicate($qb, $parameter, $term, $entryAlias)
                : $this->substringPredicate($qb, $parameter, $term, $entryAlias);
        }

        return '(' . implode(' AND ', $predicates) . ')';
    }

    /**
     * A summary is nullable, and NULL LIKE … is never true, so the OR alone
     * handles an entry that carries no summary.
     */
    private function substringPredicate(QueryBuilder $qb, string $parameter, string $term, string $entryAlias): string
    {
        $qb->setParameter($parameter, LikePattern::containing($term));

        return \sprintf(
            "(%s LIKE :%s ESCAPE '%s' OR %s LIKE :%s ESCAPE '%s')",
            \sprintf('%s.title', $entryAlias),
            $parameter,
            LikePattern::ESCAPE_CHARACTER,
            \sprintf('%s.summary', $entryAlias),
            $parameter,
            LikePattern::ESCAPE_CHARACTER,
        );
    }

    /**
     * A cheap "%term%" LIKE runs first and rejects most rows before the REPLACE chain. It is sound only while the raw
     * term is a substring of every row the normalized check accepts, so a term with boundary punctuation skips it:
     * "E-Mail" must match "E–Mail", which does not contain it.
     */
    private function wholeWordPredicate(QueryBuilder $qb, string $parameter, string $term, string $entryAlias): string
    {
        $word = $parameter . 'Word';
        $cheap = WordBoundaries::areIn($term) ? null : $parameter . 'Cheap';

        $qb->setParameter($word, LikePattern::wholeWord($term));
        if ($cheap !== null) {
            $qb->setParameter($cheap, LikePattern::containing($term));
        }

        return \sprintf(
            '(%s OR %s)',
            $this->wholeWordColumnPredicate('title', $cheap, $word, $entryAlias),
            $this->wholeWordColumnPredicate('summary', $cheap, $word, $entryAlias),
        );
    }

    /**
     * One column's half of wholeWordPredicate: the cheap "%term%" scan first
     * when it is sound, the normalized boundary check for the rows that
     * survive it.
     */
    private function wholeWordColumnPredicate(string $column, ?string $cheap, string $word, string $entryAlias): string
    {
        $escape = LikePattern::ESCAPE_CHARACTER;
        $normalized = \sprintf(
            "CONCAT(' ', NORMALIZE_WORD_BOUNDARIES(%s.%s), ' ') LIKE :%s ESCAPE '%s'",
            $entryAlias,
            $column,
            $word,
            $escape,
        );

        if ($cheap === null) {
            return '(' . $normalized . ')';
        }

        return \sprintf(
            "(%s.%s LIKE :%s ESCAPE '%s' AND %s)",
            $entryAlias,
            $column,
            $cheap,
            $escape,
            $normalized,
        );
    }
}
