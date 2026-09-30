<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoVariableClassOrMethodNameRule> */
final class NoVariableClassOrMethodNameRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoVariableClassOrMethodNameRule();
    }

    public function testItReportsEveryVariableClassOrMethodNameInApplicationCodeOnly(): void
    {
        $this->analyse(
            [__DIR__ . '/data/no-variable-class-or-method-name-fixtures.php'],
            [
                [NoVariableClassOrMethodNameRule::MESSAGE, 28],
                [NoVariableClassOrMethodNameRule::MESSAGE, 29],
                [NoVariableClassOrMethodNameRule::MESSAGE, 30],
                [NoVariableClassOrMethodNameRule::MESSAGE, 31],
                [NoVariableClassOrMethodNameRule::MESSAGE, 32],
                [NoVariableClassOrMethodNameRule::MESSAGE, 33],
                [NoVariableClassOrMethodNameRule::MESSAGE, 34],
                [NoVariableClassOrMethodNameRule::MESSAGE, 35],
                [NoVariableClassOrMethodNameRule::MESSAGE, 36],
                [NoVariableClassOrMethodNameRule::MESSAGE, 37],
                [NoVariableClassOrMethodNameRule::MESSAGE, 38],
            ],
        );
    }
}
