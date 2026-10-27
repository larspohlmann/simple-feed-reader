<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\WordBoundaries;
use App\Entity\EntryTranscript;
use App\Service\Search\Model\SearchTermsModel;
use App\Service\Search\Support\LikePattern;
use Doctrine\ORM\QueryBuilder;

/**
 * One search's terms compiled into a title, summary and transcript-text LIKE predicate, and bound
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
            $columnMatch = $terms->isWholeWord
                ? $this->wholeWordMatch($qb, $parameter, $term)
                : $this->substringMatch($qb, $parameter, $term);
            $predicates[] = self::anyColumn($columnMatch, $parameter, $entryAlias);
        }

        return '(' . implode(' AND ', $predicates) . ')';
    }

    /**
     * A summary is nullable and an entry may have no transcript row, and NULL LIKE … is never true, so the ORs alone
     * handle both. The transcript is reached by a correlated EXISTS over its unique entry_id, never a join.
     *
     * @param \Closure(string): string $columnMatch
     */
    private static function anyColumn(\Closure $columnMatch, string $parameter, string $entryAlias): string
    {
        $transcript = 'transcript' . $entryAlias . $parameter;

        return \sprintf(
            '(%s OR %s OR EXISTS (SELECT %s.id FROM %s %s WHERE %s.entry = %s AND %s))',
            $columnMatch($entryAlias . '.headline.title'),
            $columnMatch($entryAlias . '.summary'),
            $transcript,
            EntryTranscript::class,
            $transcript,
            $transcript,
            $entryAlias,
            $columnMatch($transcript . '.searchText'),
        );
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
