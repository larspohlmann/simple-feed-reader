<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Service\Search\WordBoundaries;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
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
 * stay identical, which is why neither spells the list out itself. SQLite
 * runs that PHP directly, as a function SqliteConnectionSetupDriver registers
 * on every connection; MySQL gets the list as a REPLACE chain.
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
