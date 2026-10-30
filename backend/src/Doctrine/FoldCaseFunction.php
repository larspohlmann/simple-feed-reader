<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * FOLD_CASE(expr) lower-cases a haystack for a LIKE against a CaseFolding::fold()ed term. MySQL gets the bare
 * expression: the searched columns are utf8mb4_0900_ai_ci, so LOWER() would only cost a pass over every row.
 */
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
        $sql = $sqlWalker->walkStringPrimary($this->stringExpression);
        if ($sqlWalker->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            return \sprintf('%s(%s)', self::NAME, $sql);
        }

        return $sql;
    }
}
