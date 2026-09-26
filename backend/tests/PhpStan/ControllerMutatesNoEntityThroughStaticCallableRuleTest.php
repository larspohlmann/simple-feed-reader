<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;

/**
 * @extends ControllerEntityUseRuleTestCase<ControllerMutatesNoEntityThroughStaticCallableRule>
 */
final class ControllerMutatesNoEntityThroughStaticCallableRuleTest extends ControllerEntityUseRuleTestCase
{
    protected function getRule(): Rule
    {
        return new ControllerMutatesNoEntityThroughStaticCallableRule(new ControllerEntityUse());
    }

    public function testItFlagsAStaticCallableThatReturnsAMappedClassButNotAPureHelper(): void
    {
        $this->analyse(
            [self::FIXTURES],
            [
                // User::normalizeEmail(...) and Widget::slug(...) on lines 170 and 172 return strings.
                [self::disguisedConstructionMessage(self::WIDGET, 'named'), 171],
            ],
        );
    }
}
