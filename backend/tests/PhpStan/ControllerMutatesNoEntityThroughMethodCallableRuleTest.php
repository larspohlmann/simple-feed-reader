<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;

/**
 * @extends ControllerEntityUseRuleTestCase<ControllerMutatesNoEntityThroughMethodCallableRule>
 */
final class ControllerMutatesNoEntityThroughMethodCallableRuleTest extends ControllerEntityUseRuleTestCase
{
    protected function getRule(): Rule
    {
        return new ControllerMutatesNoEntityThroughMethodCallableRule(new ControllerEntityUse());
    }

    public function testItFlagsTakingAMutatingMethodOfAMappedClassAsACallableButNotAQuery(): void
    {
        $this->analyse(
            [self::FIXTURES],
            [
                // $widget->getLabel(...) on line 168 is a query.
                [self::mutationMessage('App\Entity\Tag', 'setName'), 169],
            ],
        );
    }
}
