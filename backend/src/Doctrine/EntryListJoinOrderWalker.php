<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Query\AST\SelectClause;
use Doctrine\ORM\Query\SqlWalker;

/**
 * Prefixes the entry-list SELECT with a MySQL `JOIN_PREFIX` optimizer hint that
 * forces the `entry` table first in the join order. Only then does MySQL drive
 * the query from idx_entry_effective and stop after one page, rather than read
 * every subscribed entry into a temporary table to sort it (#1040). The hint is
 * an optimizer-hint comment, which SQLite reads as a plain comment, so one SQL
 * string serves both engines.
 *
 * Attach it per query through Query::HINT_CUSTOM_OUTPUT_WALKER only for the
 * shapes that gain (EntryQuery::isDateOrderedFanIn) — the repository decides,
 * this walker only renders. The duplicate-collapse subselect renders through
 * walkSimpleSelectClause, so it stays unhinted.
 */
final class EntryListJoinOrderWalker extends SqlWalker
{
    private const string ENTRY_DQL_ALIAS = 'e';

    public function walkSelectClause(SelectClause $selectClause): string
    {
        $select = parent::walkSelectClause($selectClause);
        $entryTable = $this->getMetadataForDqlAlias(self::ENTRY_DQL_ALIAS)->getTableName();
        $entryAlias = $this->getSQLTableAlias($entryTable, self::ENTRY_DQL_ALIAS);

        return 'SELECT /*+ JOIN_PREFIX(' . $entryAlias . ') */ ' . substr($select, \strlen('SELECT '));
    }
}
