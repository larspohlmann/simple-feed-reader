<?php

declare(strict_types=1);

namespace App\Doctrine;

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
 * The punctuation list is a deliberate subset, not an attempt at completeness:
 * sentence punctuation, brackets, straight and typographic quotes, the hyphen
 * and its longer dashes, and the slash — what German and English prose
 * actually puts against a word. Anything left off this list simply fails to
 * be a word boundary; that is a conscious trade, not an oversight.
 */
final class NormalizeWordBoundariesFunction extends FunctionNode
{
    private const array BOUNDARY_CHARACTERS = [
        '.', ',', ';', ':', '!', '?',
        '(', ')', '[', ']', '{', '}',
        '"', "'", '„', '“', '”', '‚', '‘', '’', '«', '»',
        '-', '–', '—',
        '/',
    ];

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

        foreach (self::BOUNDARY_CHARACTERS as $character) {
            $sql = \sprintf("REPLACE(%s, '%s', ' ')", $sql, str_replace("'", "''", $character));
        }

        return $sql;
    }
}
