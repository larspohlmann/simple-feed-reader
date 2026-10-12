<?php

declare(strict_types=1);

namespace App\Tests\Service\Text\Support;

use App\Service\Text\Support\HtmlAnchor;
use PHPUnit\Framework\TestCase;

final class HtmlAnchorTest extends TestCase
{
    public function testEscapesBothTheTargetAndTheText(): void
    {
        self::assertSame(
            '<a href="https://example.com/?a=1&amp;b=&quot;2&quot;">Carol &lt;3</a>',
            HtmlAnchor::to('https://example.com/?a=1&b="2"', 'Carol <3'),
        );
    }
}
