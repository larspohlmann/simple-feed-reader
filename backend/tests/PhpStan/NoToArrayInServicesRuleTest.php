<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoToArrayInServicesRule> */
final class NoToArrayInServicesRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoToArrayInServicesRule();
    }

    public function testItReportsAResponseShaperOnAServiceClassOnly(): void
    {
        $this->analyse(
            [__DIR__ . '/data/no-to-array-in-services-fixtures.php'],
            [
                [self::message('toArray', 'App\Service\Fixtures'), 11],
                [self::message('jsonSerialize', 'App\Service\Fixtures'), 16],
                [self::message('TOARRAY', 'App\Service\Fixtures\Nested'), 26],
            ],
        );
    }

    private static function message(string $method, string $namespaceName): string
    {
        return sprintf(
            'Services build no response arrays: %s() in %s. '
            . 'Map the value in a src/Http/*Json mapper, or name a store\'s serialiser after the store (#1182).',
            $method,
            $namespaceName,
        );
    }
}
