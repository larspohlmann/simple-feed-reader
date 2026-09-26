<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @template TRule of Rule
 * @extends RuleTestCase<TRule>
 */
abstract class ControllerEntityUseRuleTestCase extends RuleTestCase
{
    protected const string FIXTURES = __DIR__ . '/data/controller-mutates-no-entity-fixtures.php';
    protected const string WIDGET = 'App\Entity\Fixtures\Widget';
    private const string ADVICE = 'An action reads the request, delegates, and returns a response; construct and '
        . 'change entities in a service under src/Service, which also persists them (#1157).';

    // The rules read each class's Doctrine mapping, and the test reflector only finds fixture classes once declared.
    public static function setUpBeforeClass(): void
    {
        require_once self::FIXTURES;
    }

    protected static function constructionMessage(string $class): string
    {
        return sprintf('A controller constructs the entity %s. %s', $class, self::ADVICE);
    }

    protected static function mutationMessage(string $class, string $method): string
    {
        return sprintf('A controller calls %s::%s(), which changes an entity. %s', $class, $method, self::ADVICE);
    }

    protected static function disguisedConstructionMessage(string $class, string $method): string
    {
        return sprintf(
            'A controller calls %s::%s(), a static method that returns an entity: a disguised construction. %s',
            $class,
            $method,
            self::ADVICE,
        );
    }
}
