<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Support;

use App\Service\Reader\Support\LeadingEngagementBlocks;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;

final class LeadingEngagementBlocksTest extends TestCase
{
    use ParsesHtml;

    public function testAnElementWrappingExactlyOneMatchingTimeIsTimeOnly(): void
    {
        $element = $this->document('<div><time>21:15</time></div>')->querySelector('div');
        self::assertNotNull($element);

        self::assertTrue(LeadingEngagementBlocks::isTimeOnly($element));
    }

    public function testAnElementWithTwoTimeDescendantsIsNotTimeOnlyEvenWhenTheTextsLineUp(): void
    {
        // Two <time> tags, the second empty: the wrapper's whole text still
        // equals the first time's text, but there is more than one time
        // descendant, so this is not a bare time badge.
        $element = $this->document('<div><time>21:15</time><time></time></div>')->querySelector('div');
        self::assertNotNull($element);

        self::assertFalse(LeadingEngagementBlocks::isTimeOnly($element));
    }
}
