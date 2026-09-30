<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoVariableClassOrMethodNameThroughStaticCallableRule> */
final class NoVariableClassOrMethodNameThroughStaticCallableRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoVariableClassOrMethodNameThroughStaticCallableRule();
    }

    public function testItReportsVariableNamedFirstClassCallablesInApplicationCodeOnly(): void
    {
        $this->analyse(
            [__DIR__ . '/data/no-variable-class-or-method-name-fixtures.php'],
            [
                [NoVariableClassOrMethodNameRule::MESSAGE, 39],
                [NoVariableClassOrMethodNameRule::MESSAGE, 40],
            ],
        );
    }
}
