<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<EntityIdCoercionRule> */
final class EntityIdCoercionRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new EntityIdCoercionRule();
    }

    public function testItReportsACastAndADefaultOnAnEntityIdOnly(): void
    {
        $this->analyse(
            [__DIR__ . '/data/entity-id-coercion-fixtures.php'],
            [
                [EntityIdCoercionRule::MESSAGE, 20],
                [EntityIdCoercionRule::MESSAGE, 21],
                [EntityIdCoercionRule::MESSAGE, 25],
                [EntityIdCoercionRule::MESSAGE, 26],
            ],
        );
    }
}
