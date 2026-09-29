<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<InvocationMatchersOnThisRule> */
final class InvocationMatchersOnThisRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new InvocationMatchersOnThisRule();
    }

    public function testItReportsAMatcherCalledStaticallyInATestCase(): void
    {
        $this->analyse(
            [__DIR__ . '/data/invocation-matchers-on-this-fixtures.php'],
            [
                [self::message('self', 'once'), 17],
                [self::message('static', 'never'), 18],
                [self::message('self', 'exactly'), 19],
            ],
        );
    }

    private static function message(string $receiver, string $matcher): string
    {
        return sprintf(
            'Call invocation matchers on $this: %s::%s() is $this->%s() (#1169).',
            $receiver,
            $matcher,
            $matcher,
        );
    }
}
