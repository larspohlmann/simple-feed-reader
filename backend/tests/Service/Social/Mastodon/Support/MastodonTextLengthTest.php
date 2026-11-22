<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Mastodon\Support;

use App\Service\Social\Mastodon\Support\MastodonTextLength;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MastodonTextLengthTest extends TestCase
{
    #[DataProvider('texts')]
    public function testCountsAsTheComposerDoes(string $text, int $length): void
    {
        self::assertSame($length, MastodonTextLength::measureLength($text));
    }

    /** @return iterable<string, array{string, int}> */
    public static function texts(): iterable
    {
        yield 'plain text in code points' => ['Hallo', 5];
        yield 'a long link counts 23' => ['see https://example.com/' . str_repeat('a', 200), 4 + 23];
        yield 'every link counts 23' => ['http://a.co https://b.example/x', 23 + 1 + 23];
        yield 'a link swallows the punctuation after it' => ['(https://example.com/a).', 1 + 23];
        yield 'a non-breaking space ends a link' => ["https://example.com/a\u{00A0}hi", 23 + 3];
        yield 'an ideographic space ends a link' => ["x\u{3000}https://e.x/\u{3000}y", 1 + 1 + 23 + 1 + 1];
        yield 'a byte order mark ends a link' => ["\u{FEFF}https://a.b/\u{FEFF}z", 1 + 23 + 1 + 1];
        yield 'a next-line character does not end a link' => ["https://a.b/\u{0085}x", 23];
        yield 'a tab ends a link' => ["https://a.b/\tx", 23 + 2];
        yield 'a remote mention counts as its username' => ['@alice@example.social hi', 9];
        yield 'a remote mention after a letter outside ASCII' => ['é@bob@x.y', 5];
        yield 'a remote mention after an emoji' => ['😀@bob@x.y', 5];
        yield 'a remote mention ends on a word character' => ['@bob@x.', 5];
        yield 'a remote mention after a space' => ['hi @alice@example.social', 9];
        yield 'a local mention counts in full' => ['@alice hi', 9];
        yield 'a mention after a slash is no mention' => ['a/@alice@example.social', 23];
        yield 'a mention after a word character is no mention' => ['a@alice@example.social', 22];
        yield 'an emoji with a skin tone is two code points' => ['👍🏽', 2];
        yield 'umlauts are one code point each' => ['Grüße', 5];
        yield 'nothing' => ['', 0];
    }
}
