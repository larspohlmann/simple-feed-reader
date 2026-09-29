<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<CommentBlockLengthRule> */
final class CommentBlockLengthRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/data/comment-block-length/';

    private const string UNSWEPT_FIXTURE = 'tests/PhpStan/data/comment-block-length/allow-listed.php';

    private const string MESSAGE = 'This comment has %d lines of prose; CLAUDE.md allows three at the absolute most. '
        . 'Rename, extract, or move the reasoning to docs/ or the commit message.';

    /** @var list<string> */
    private array $unsweptFiles = [];

    protected function getRule(): Rule
    {
        return new CommentBlockLengthRule(
            new CommentBlocks(new CommentProse(new PhpDocTypeReader())),
            $this->unsweptFiles,
        );
    }

    public function testCommentsWithOnlyWhitespaceBetweenThemAreOneBlock(): void
    {
        $this->analyse([self::FIXTURES . 'block-joins.php'], [
            [self::message(4), 14],
            [self::message(4), 23],
            [self::message(4), 33],
            [self::message(4), 42],
        ]);
    }

    public function testDelimiterLinesAreNotProseAndTheFourthProseLineFails(): void
    {
        $this->analyse([self::FIXTURES . 'prose-limit.php'], [
            [self::message(4), 21],
            [self::message(5), 31],
            [self::message(4), 42],
        ]);
    }

    public function testAVarOneLinerIsNotProse(): void
    {
        $this->analyse([self::FIXTURES . 'var-one-liners.php'], [
            [self::message(4), 26],
        ]);
    }

    public function testATypeIsNotProseButItsDescriptionIs(): void
    {
        $this->analyse([self::FIXTURES . 'type-shapes.php'], [
            [self::message(4), 51],
        ]);
    }

    public function testADirectiveIsNotProseButItsReasonIs(): void
    {
        $this->analyse([self::FIXTURES . 'tool-directives.php'], [
            [self::message(4), 34],
            [self::message(4), 42],
        ]);
    }

    public function testAnUnsweptFileIsSkipped(): void
    {
        $this->unsweptFiles = [self::UNSWEPT_FIXTURE];

        $this->analyse([self::FIXTURES . 'allow-listed.php'], []);
    }

    public function testAFileOffTheListIsChecked(): void
    {
        $this->analyse([self::FIXTURES . 'allow-listed.php'], [
            [self::message(4), 12],
        ]);
    }

    private static function message(int $proseLines): string
    {
        return sprintf(self::MESSAGE, $proseLines);
    }
}
