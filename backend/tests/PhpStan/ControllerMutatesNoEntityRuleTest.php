<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ControllerMutatesNoEntityRule>
 */
final class ControllerMutatesNoEntityRuleTest extends RuleTestCase
{
    private const string WIDGET = 'App\Entity\Fixtures\Widget';
    private const string ADVICE = 'An action reads the request, delegates, and returns a response; construct and '
        . 'change entities in a service under src/Service, which also persists them (#1157).';

    protected function getRule(): Rule
    {
        return new ControllerMutatesNoEntityRule();
    }

    public function testItFlagsEntityConstructionAndMutationInControllersButNotQueriesOrRequireId(): void
    {
        $this->analyse(
            [__DIR__ . '/data/controller-mutates-no-entity-fixtures.php'],
            [
                [$this->constructionMessage(), 50],
                [$this->mutationMessage('setLabel'), 55],
                [$this->mutationMessage('rename'), 56],
                [$this->mutationMessage('setLabel'), 57],
                [$this->mutationMessage('setLabel'), 64],
                // getLabel(), isVisible() (line 59) and requireId() (line 69) are queries, so they are not reported.
            ],
        );
    }

    private function constructionMessage(): string
    {
        return sprintf('A controller constructs the entity %s. %s', self::WIDGET, self::ADVICE);
    }

    private function mutationMessage(string $method): string
    {
        return sprintf(
            'A controller calls %s::%s(), which changes an entity. %s',
            self::WIDGET,
            $method,
            self::ADVICE,
        );
    }
}
