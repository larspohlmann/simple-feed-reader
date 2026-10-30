<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\CaseFolding;
use App\Doctrine\FoldCaseFunction;
use App\Doctrine\NormalizeWordBoundariesFunction;
use App\Entity\EntryTranscript;
use App\Service\Search\Model\SearchTermsModel;
use App\Service\Search\Support\LikePattern;
use Doctrine\DBAL\Platforms\SQLitePlatform;
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
        if (!CaseFolding::isNeededFor($term) || !self::likeFoldsAsciiAlone($qb)) {
            $qb->setParameter($parameter, LikePattern::containing($term));

            return static fn (string $column): string => self::like($column, $parameter);
        }

        $qb->setParameter($parameter, LikePattern::containing(CaseFolding::fold($term)));
        $anchor = $this->bindAnchor($qb, $parameter, $term);

        return static fn (string $column): string => self::anchored(
            $column,
            $anchor,
            self::like(\sprintf('%s(%s)', FoldCaseFunction::NAME, $column), $parameter),
        );
    }

    /**
     * NORMALIZE_WORD_BOUNDARIES folds case as well on SQLite, so the word is folded alike.
     *
     * @return \Closure(string): string
     */
    private function wholeWordMatch(QueryBuilder $qb, string $parameter, string $term): \Closure
    {
        $word = $parameter . 'Word';
        $qb->setParameter($word, LikePattern::wholeWord(CaseFolding::fold($term)));
        $anchor = $this->bindAnchor($qb, $parameter, $term);

        return static fn (string $column): string => self::anchored(
            $column,
            $anchor,
            self::like(\sprintf("CONCAT(' ', %s(%s), ' ')", NormalizeWordBoundariesFunction::NAME, $column), $word),
        );
    }

    private function bindAnchor(QueryBuilder $qb, string $parameter, string $term): ?string
    {
        $anchor = LikePattern::anchor($term);
        if ($anchor === null) {
            return null;
        }

        $qb->setParameter($parameter . 'Anchor', LikePattern::containing($anchor));

        return $parameter . 'Anchor';
    }

    private static function likeFoldsAsciiAlone(QueryBuilder $qb): bool
    {
        return $qb->getEntityManager()->getConnection()->getDatabasePlatform() instanceof SQLitePlatform;
    }

    private static function anchored(string $column, ?string $anchor, string $match): string
    {
        if ($anchor === null) {
            return '(' . $match . ')';
        }

        return \sprintf('(%s AND %s)', self::like($column, $anchor), $match);
    }

    private static function like(string $haystack, string $parameter): string
    {
        return \sprintf("%s LIKE :%s ESCAPE '%s'", $haystack, $parameter, LikePattern::ESCAPE_CHARACTER);
    }
}
