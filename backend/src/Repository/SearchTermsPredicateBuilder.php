<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Search\LikePattern;
use App\Service\Search\SearchTerms;
use App\Service\Search\WordBoundaries;
use Doctrine\ORM\QueryBuilder;

/**
 * One search's terms compiled into a title/summary LIKE predicate, and bound
 * onto the QueryBuilder it is handed. Needs no repository state of its own.
 */
final readonly class SearchTermsPredicateBuilder
{
    /**
     * One search's terms as a single ANDed expression the caller places itself.
     * The combined saved-search read ORs several of these, which andWhere()
     * cannot express. $prefix keys the bound parameters, so two searches that
     * share a word cannot overwrite each other's value.
     */
    public function build(QueryBuilder $qb, SearchTerms $terms, string $prefix): string
    {
        $predicates = [];
        foreach ($terms->terms as $position => $term) {
            $parameter = $prefix . $position;
            $predicates[] = $terms->isWholeWord
                ? $this->wholeWordPredicate($qb, $parameter, $term)
                : $this->substringPredicate($qb, $parameter, $term);
        }

        return '(' . implode(' AND ', $predicates) . ')';
    }

    /**
     * A summary is nullable, and NULL LIKE … is never true, so the OR alone
     * handles an entry that carries no summary.
     */
    private function substringPredicate(QueryBuilder $qb, string $parameter, string $term): string
    {
        $qb->setParameter($parameter, LikePattern::containing($term));

        return \sprintf(
            "(e.title LIKE :%s ESCAPE '%s' OR e.summary LIKE :%s ESCAPE '%s')",
            $parameter,
            LikePattern::ESCAPE_CHARACTER,
            $parameter,
            LikePattern::ESCAPE_CHARACTER,
        );
    }

    /**
     * The plain "LIKE %term%" is ANDed in front of the normalized whole-word
     * check on purpose: it rejects almost every row with a cheap scan before
     * the expensive REPLACE chain runs, and costs nothing extra on the rows
     * where it does match.
     *
     * It is sound only while the raw term is a substring of every row the
     * normalized check would accept — true for a term of letters and digits,
     * FALSE as soon as the term carries boundary punctuation, because the two
     * sides then differ in exactly that punctuation. "E-Mail" and "E–Mail"
     * (en dash) normalize alike and must both match, yet neither is a raw
     * substring of the other. Such a term skips the prefilter and pays for the
     * chain; it is the rare shape, and a wrong answer is not worth the scan.
     */
    private function wholeWordPredicate(QueryBuilder $qb, string $parameter, string $term): string
    {
        $word = $parameter . 'Word';
        $cheap = WordBoundaries::areIn($term) ? null : $parameter . 'Cheap';

        $qb->setParameter($word, LikePattern::wholeWord($term));
        if ($cheap !== null) {
            $qb->setParameter($cheap, LikePattern::containing($term));
        }

        return \sprintf(
            '(%s OR %s)',
            $this->wholeWordColumnPredicate('title', $cheap, $word),
            $this->wholeWordColumnPredicate('summary', $cheap, $word),
        );
    }

    /**
     * One column's half of wholeWordPredicate: the cheap "%term%" scan first
     * when it is sound, the normalized boundary check for the rows that
     * survive it.
     */
    private function wholeWordColumnPredicate(string $column, ?string $cheap, string $word): string
    {
        $escape = LikePattern::ESCAPE_CHARACTER;
        $normalized = \sprintf(
            "CONCAT(' ', NORMALIZE_WORD_BOUNDARIES(e.%s), ' ') LIKE :%s ESCAPE '%s'",
            $column,
            $word,
            $escape,
        );

        if ($cheap === null) {
            return '(' . $normalized . ')';
        }

        return \sprintf(
            "(e.%s LIKE :%s ESCAPE '%s' AND %s)",
            $column,
            $cheap,
            $escape,
            $normalized,
        );
    }
}
