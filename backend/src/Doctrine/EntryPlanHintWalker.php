<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Doctrine\Exception\MissingEntryPlanHintException;
use Doctrine\ORM\Query\AST\SelectClause;
use Doctrine\ORM\Query\SqlWalker;

/**
 * Prefixes an entry query's outer SELECT with the MySQL optimizer-hint comment
 * for the EntryPlanHint the caller chose — a JOIN_PREFIX to drive the join from
 * `entry`, optionally with an INDEX hint too (#1040, #1098). SQLite reads an
 * optimizer-hint comment as a plain comment, so one SQL string serves both
 * engines.
 *
 * Attach it per query through Query::HINT_CUSTOM_OUTPUT_WALKER, paired with
 * self::HINT carrying the chosen EntryPlanHint — the repository decides both,
 * this walker only renders. The duplicate-collapse subselect renders through
 * walkSimpleSelectClause, so it stays unhinted.
 */
final class EntryPlanHintWalker extends SqlWalker
{
    public const string HINT = self::class . '.planHint';

    private const string ENTRY_DQL_ALIAS = 'e';

    public function walkSelectClause(SelectClause $selectClause): string
    {
        $select = parent::walkSelectClause($selectClause);
        $entryTable = $this->getMetadataForDqlAlias(self::ENTRY_DQL_ALIAS)->getTableName();
        $entryAlias = $this->getSQLTableAlias($entryTable, self::ENTRY_DQL_ALIAS);
        $comment = $this->planHint()->optimizerHint($entryAlias);

        return 'SELECT /*+ ' . $comment . ' */ ' . substr($select, \strlen('SELECT '));
    }

    private function planHint(): EntryPlanHint
    {
        $hint = $this->getQuery()->getHint(self::HINT);

        return $hint instanceof EntryPlanHint ? $hint : throw new MissingEntryPlanHintException();
    }
}
