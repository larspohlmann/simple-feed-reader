<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoCollaboratorDefaultRule> */
final class NoCollaboratorDefaultRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoCollaboratorDefaultRule();
    }

    public function testItReportsAServiceThatDefaultsACollaboratorWithNew(): void
    {
        $this->analyse(
            [__DIR__ . '/data/no-collaborator-default-fixtures.php'],
            [
                [self::message('App\Service\Fixtures\DefaultsItsHelper', 'helper'), 16],
                [self::message('App\Controller\Fixtures\ControllerDefaults', 'helper'), 42],
            ],
        );
    }

    private static function message(string $className, string $parameter): string
    {
        return sprintf(
            'Inject the collaborator: %s::__construct() defaults $%s with new (#1169).',
            $className,
            $parameter,
        );
    }
}
