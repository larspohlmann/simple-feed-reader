<?php

declare(strict_types=1);

namespace App\Tests\Service\Text;

use App\Service\Text\Whitespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WhitespaceTest extends TestCase
{
    /** @return iterable<string, array{?string, string}> */
    public static function texts(): iterable
    {
        yield 'ascii runs fold and the ends trim' => ["  a \n\t b  ", 'a b'];
        yield 'a no-break space run' => ["a\u{00A0}\u{00A0}b", 'a b'];
        yield 'thin and ideographic spaces' => ["a\u{2009}b\u{3000}c", 'a b c'];
        yield 'unicode space at both ends' => ["\u{00A0}a\u{00A0}", 'a'];
        yield 'a soft hyphen is not whitespace' => ["Gesundheits\u{00AD}ministerin", "Gesundheits\u{00AD}ministerin"];
        yield 'only whitespace' => [" \u{00A0}\n", ''];
        yield 'no text at all' => [null, ''];
    }

    #[DataProvider('texts')]
    public function testCollapsesEveryWhitespaceRunToOneSpace(?string $text, string $collapsed): void
    {
        self::assertSame($collapsed, Whitespace::collapse($text));
    }
}
