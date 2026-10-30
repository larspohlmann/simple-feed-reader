<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\NormalizeWordBoundariesFunction;
use App\Doctrine\SearchFoldFunction;
use App\Doctrine\SearchFolding;
use App\Doctrine\SearchIndexMatchFunction;
use App\Service\Search\Model\SearchTermsModel;
use App\Service\Search\Support\LikePattern;
use App\Service\Search\Support\SearchIndexQuery;
use Doctrine\ORM\QueryBuilder;

/**
 * The search on SQLite: every piece the search index can find goes into one MATCH, which ignores case and accents. A
 * term too short for it, and each whole-word check, fold the haystack in PHP instead, on the index's candidates only.
 */
final readonly class IndexSearchPredicates
{
    public function build(QueryBuilder $qb, SearchTermsModel $terms, string $prefix, string $entryAlias): string
    {
        $indexed = [];
        $checks = [];
        foreach ($terms->terms as $position => $term) {
            $parameter = $prefix . $position;
            if ($terms->isWholeWord) {
                array_push($indexed, ...SearchIndexQuery::wholeWordPieces($term));
                $match = $this->wholeWordMatch($qb, $parameter, $term);
                $checks[] = SearchedColumns::anyOf($match, $parameter, $entryAlias);
                continue;
            }
            if (SearchIndexQuery::isIndexable($term)) {
                $indexed[] = $term;
                continue;
            }
            $match = $this->foldedSubstringMatch($qb, $parameter, $term);
            $checks[] = SearchedColumns::anyOf($match, $parameter, $entryAlias);
        }

        if ($indexed !== []) {
            array_unshift($checks, $this->indexMatch($qb, $prefix . 'Index', $indexed, $entryAlias));
        }

        return '(' . implode(' AND ', $checks) . ')';
    }

    /** @param non-empty-list<string> $pieces */
    private function indexMatch(QueryBuilder $qb, string $parameter, array $pieces, string $entryAlias): string
    {
        $qb->setParameter($parameter, SearchIndexQuery::allOf($pieces));

        return \sprintf('%s(%s.id, :%s) = 1', SearchIndexMatchFunction::NAME, $entryAlias, $parameter);
    }

    /** @return \Closure(string): string */
    private function foldedSubstringMatch(QueryBuilder $qb, string $parameter, string $term): \Closure
    {
        $qb->setParameter($parameter, LikePattern::containing(SearchFolding::fold($term)));

        return static fn (string $column): string => self::like(
            \sprintf('%s(%s)', SearchFoldFunction::NAME, $column),
            $parameter,
        );
    }

    /**
     * NORMALIZE_WORD_BOUNDARIES folds on SQLite as well, so the word is folded alike.
     *
     * @return \Closure(string): string
     */
    private function wholeWordMatch(QueryBuilder $qb, string $parameter, string $term): \Closure
    {
        $qb->setParameter($parameter, LikePattern::wholeWord(SearchFolding::fold($term)));

        return static fn (string $column): string => self::like(
            \sprintf("CONCAT(' ', %s(%s), ' ')", NormalizeWordBoundariesFunction::NAME, $column),
            $parameter,
        );
    }

    private static function like(string $haystack, string $parameter): string
    {
        return \sprintf("%s LIKE :%s ESCAPE '%s'", $haystack, $parameter, LikePattern::ESCAPE_CHARACTER);
    }
}
