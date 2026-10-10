<?php

declare(strict_types=1);

namespace App\Tests\Service\Text\Support;

use App\Service\Text\Support\DerivedTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DerivedTitleTest extends TestCase
{
    /** @return iterable<string, array{string|null, string|null}> */
    public static function bodies(): iterable
    {
        yield 'first sentence of the first line' => [
            '<p>Big video update! You can now post videos up to 10 minutes long.</p>',
            'Big video update!',
        ];
        yield 'a line without a sentence end is taken whole' => [
            '<p>a masterclass in alt text</p><p>[contains quote post or other embedded content]</p>',
            'a masterclass in alt text',
        ];
        yield 'only the first line counts' => [
            '<p>Update: group chats can now host up to 100 members<br>More soon</p>',
            'Update: group chats can now host up to 100 members',
        ];
        yield 'a dot inside a word ends no sentence' => [
            '<p>v1.132 is live! Starter packs are easier to find.</p>',
            'v1.132 is live!',
        ];
        yield 'a quote-post line of a label and a link is skipped' => [
            '<p>RE: <a href="https://graz.social/@linos/1">https://graz.social/@linos/1</a></p>'
                . '<p>Congratulations to everyone involved.</p>',
            'Congratulations to everyone involved.',
        ];
        yield 'a line holding only a link is skipped' => [
            '<p>https://example.com/a</p><p>Read this.</p>',
            'Read this.',
        ];
        yield 'a line holding only emoji is skipped' => ['<p>🎉🎉</p><p>We shipped it</p>', 'We shipped it'];
        yield 'a long sentence is cut at a word boundary' => [
            '<p>Today we are sharing additional guidelines about our Trade Mark Policy based on community '
                . 'feedback that we received.</p>',
            'Today we are sharing additional guidelines about our Trade Mark Policy based…',
        ];
        yield 'a long word with no space is cut hard' => [
            '<p>' . str_repeat('a', 100) . '</p>',
            str_repeat('a', 79) . '…',
        ];
        yield 'entities are decoded' => ['<p>Fish &amp; chips</p>', 'Fish & chips'];
        yield 'no words at all' => ['<p>https://example.com/a</p>', null];
        yield 'empty body' => ['', null];
        yield 'no body' => [null, null];
    }

    #[DataProvider('bodies')]
    public function testDerivesTheTitle(?string $bodyHtml, ?string $expected): void
    {
        self::assertSame($expected, DerivedTitle::from($bodyHtml));
    }

    public function testACutTitleIsAtMostEightyCharacters(): void
    {
        $title = DerivedTitle::from('<p>' . str_repeat('word ', 40) . '</p>');

        self::assertNotNull($title);
        self::assertLessThanOrEqual(80, mb_strlen($title));
        self::assertStringEndsWith('word…', $title);
    }
}
