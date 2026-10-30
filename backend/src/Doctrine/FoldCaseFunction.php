<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/** FOLD_CASE(expr): SQLite's UDF for CaseFolding::fold(), emitted only where LIKE folds ASCII alone. */
final class FoldCaseFunction extends FunctionNode
{
    public const string NAME = 'FOLD_CASE';

    private Node $stringExpression;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->stringExpression = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return \sprintf('%s(%s)', self::NAME, $sqlWalker->walkStringPrimary($this->stringExpression));
    }
}
