<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<ChainedNullCoalescingRule> */
final class ChainedNullCoalescingRuleTest extends RuleTestCase
{
    private const string MESSAGE = 'A null-coalescing chain can contain at most two operators. '
        . 'Extract the fallback selection into a named method or explicit control flow.';

    protected function getRule(): Rule
    {
        return new ChainedNullCoalescingRule();
    }

    public function testItReportsEachLongChainOnce(): void
    {
        $this->analyse(
            [__DIR__ . '/data/chained-null-coalescing-fixtures.php'],
            [
                [self::MESSAGE, 12],
                [self::MESSAGE, 22],
            ],
        );
    }
}
