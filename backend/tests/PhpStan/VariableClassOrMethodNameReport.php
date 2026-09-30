<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;

/** The verdict the variable class or method name rules share: application code is reported, tests are exempt. */
final readonly class VariableClassOrMethodNameReport
{
    /** @return list<IdentifierRuleError> */
    public static function inApplicationCode(Scope $scope): array
    {
        $namespaceName = $scope->getNamespace() ?? '';

        $isApplicationCode = ClassNameReferences::isInAnyOf($namespaceName, ['App\\'])
            && !ClassNameReferences::isInAnyOf($namespaceName, ['App\\Tests\\']);

        if (!$isApplicationCode) {
            return [];
        }

        return [
            RuleErrorBuilder::message(NoVariableClassOrMethodNameRule::MESSAGE)
                ->identifier('simpleFeedReader.variableClassOrMethodName')
                ->build(),
        ];
    }
}
