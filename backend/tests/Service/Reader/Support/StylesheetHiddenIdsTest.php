<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Support;

use App\Service\Reader\Support\StylesheetHiddenIds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StylesheetHiddenIdsTest extends TestCase
{
    public function testReadsTheIdOfARuleThatHidesIt(): void
    {
        $css = '#updateBrowserMessage{width:600px;margin:0 auto;text-align:center;display:none}'
            . '#updateBrowserMessage .messageText{line-height:26px}';

        self::assertSame(['updateBrowserMessage'], StylesheetHiddenIds::in($css));
    }

    public function testReadsEveryIdOfASelectorList(): void
    {
        self::assertSame(['first', 'second'], StylesheetHiddenIds::in('#first, #second { display: none !important; }'));
    }

    /** @return iterable<string, array{string}> */
    public static function visibleRules(): iterable
    {
        yield 'a rule that shows the element' => ['#banner{display:block}'];
        yield 'a descendant selector' => ['#banner p{display:none}'];
        yield 'a class selector' => ['.banner{display:none}'];
        yield 'an id with a qualifier' => ['#banner.open{display:none}'];
        yield 'another display value' => ['#banner{display:none-ish}'];
        yield 'a property named like display' => ['#banner{--display:none}'];
        yield 'a rule inside a media query' => ['@media (max-width: 600px){#banner{display:none}}'];
        yield 'a commented-out rule' => ['/* #banner{display:none} */'];
    }

    #[DataProvider('visibleRules')]
    public function testIgnoresARuleThatDoesNotAlwaysHideAWholeElementById(string $css): void
    {
        self::assertSame([], StylesheetHiddenIds::in($css));
    }

    public function testReadsTheTopLevelRuleAfterAMediaQuery(): void
    {
        $css = '@media print{#a{display:none}} #b{display:none}';

        self::assertSame(['b'], StylesheetHiddenIds::in($css));
    }
}
