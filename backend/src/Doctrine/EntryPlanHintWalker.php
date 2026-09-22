<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Doctrine\Exception\MissingEntryPlanHintException;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\AST\SelectClause;
use Doctrine\ORM\Query\SqlOutputWalker;

/**
 * Renders the EntryPlanHint that apply() paired onto the query as an optimizer-hint
 * comment on the entry's outer SELECT. SQLite reads the comment as a comment, so one
 * SQL string serves both engines (#1040, #1098).
 */
final class EntryPlanHintWalker extends SqlOutputWalker
{
    public const string HINT = self::class . '.planHint';

    private const string ENTRY_DQL_ALIAS = 'e';

    /**
     * @param Query<mixed, mixed> $query
     */
    public static function apply(Query $query, EntryPlanHint $planHint): void
    {
        $query->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, self::class);
        $query->setHint(self::HINT, $planHint);
    }

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
