<?php

declare(strict_types=1);

namespace App\Tests\Service\Text\Support;

use App\Service\Text\Support\HtmlEscape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlEscapeTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function texts(): iterable
    {
        yield 'markup' => ['<b>Tom & Jerry</b>', '&lt;b&gt;Tom &amp; Jerry&lt;/b&gt;'];
        yield 'both quotes' => ['"double" \'single\'', '&quot;double&quot; &apos;single&apos;'];
        yield 'an invalid byte' => ["Caf\xE9", "Caf\u{FFFD}"];
    }

    #[DataProvider('texts')]
    public function testEscapesTextForHtml(string $text, string $expected): void
    {
        self::assertSame($expected, HtmlEscape::text($text));
    }
}
