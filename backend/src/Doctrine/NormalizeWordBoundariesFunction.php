<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Service\Search\WordBoundaries;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL: NORMALIZE_WORD_BOUNDARIES(stringExpression)
 *
 * Turns punctuation that borders a word into a space, so a padded haystack
 * (" " . normalized . " ") can be tested for " term " to find a whole-word
 * match with plain LIKE — MySQL and SQLite have no negated character class,
 * so "not followed by a letter" cannot be written directly.
 *
 * This is the SQL half of the rule. `WordBoundaries` owns the character list
 * and performs the same replacement in PHP on the search term; the two must
 * stay identical, which is why neither spells the list out itself.
 */
final class NormalizeWordBoundariesFunction extends FunctionNode
{
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

        foreach (WordBoundaries::CHARACTERS as $character) {
            $sql = \sprintf("REPLACE(%s, '%s', ' ')", $sql, str_replace("'", "''", $character));
        }

        return $sql;
    }
}
