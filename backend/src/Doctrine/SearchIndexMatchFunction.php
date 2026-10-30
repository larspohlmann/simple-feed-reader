<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\AST\PathExpression;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/** SEARCH_INDEX_MATCHES(entry.id, :query): 1 when the SQLite search index matches the entry, 0 otherwise. */
final class SearchIndexMatchFunction extends FunctionNode
{
    public const string NAME = 'SEARCH_INDEX_MATCHES';

    private PathExpression $entryId;
    private Node $query;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->entryId = $parser->SingleValuedPathExpression();
        $parser->match(TokenType::T_COMMA);
        $this->query = $parser->InputParameter();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return \sprintf(
            '(%s IN (SELECT rowid FROM %s WHERE %s MATCH %s))',
            $sqlWalker->walkPathExpression($this->entryId),
            EntrySearchIndex::TABLE,
            EntrySearchIndex::TABLE,
            $this->query->dispatch($sqlWalker),
        );
    }
}
