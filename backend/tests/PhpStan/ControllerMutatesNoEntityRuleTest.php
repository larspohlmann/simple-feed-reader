<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;

/**
 * @extends ControllerEntityUseRuleTestCase<ControllerMutatesNoEntityRule>
 */
final class ControllerMutatesNoEntityRuleTest extends ControllerEntityUseRuleTestCase
{
    private const string DIMENSIONS = 'App\Entity\Fixtures\Dimensions';

    protected function getRule(): Rule
    {
        return new ControllerMutatesNoEntityRule(new ControllerEntityUse());
    }

    public function testItFlagsBuildingChangingAndStaticallyBuildingAMappedClassInAController(): void
    {
        $this->analyse(
            [self::FIXTURES],
            [
                [self::constructionMessage(self::WIDGET), 135],
                [self::mutationMessage(self::WIDGET, 'setLabel'), 140],
                [self::mutationMessage(self::WIDGET, 'rename'), 141],
                [self::mutationMessage(self::WIDGET, 'setLabel'), 142],
                [self::mutationMessage(self::WIDGET, 'setLabel'), 149],
                [self::disguisedConstructionMessage(self::WIDGET, 'named'), 182],
                [self::disguisedConstructionMessage(self::WIDGET, 'maybeNamed'), 183],
                [self::disguisedConstructionMessage(self::WIDGET, 'many'), 184],
                [self::mutationMessage(self::DIMENSIONS, 'setWidth'), 199],
                // Not reported: queries, requireId() and a pure static helper (144, 154, 179, 201), an unmapped
                // class's static returning entities (194), unmapped \ArrayObject (159, 161), the unmapped exception
                // and value objects under App\Entity (208, 211, 214, 215), and first-class callables (168-172).
            ],
        );
    }
}
