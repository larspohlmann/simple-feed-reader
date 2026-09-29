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
 * NORMALIZE_WORD_BOUNDARIES(expr) turns word-bordering punctuation into spaces, so LIKE finds " term " in " value " as
 * a whole word. WordBoundaries owns the characters for this SQL half and the PHP half alike; neither spells them out.
 */
final class NormalizeWordBoundariesFunction extends FunctionNode
{
    public const string NAME = 'NORMALIZE_WORD_BOUNDARIES';

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

        foreach (WordBoundaries::CHARACTERS as $character) {
            $sql = \sprintf("REPLACE(%s, '%s', ' ')", $sql, str_replace("'", "''", $character));
        }

        return $sql;
    }
}
