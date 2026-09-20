<?php

declare(strict_types=1);

namespace App\Tests\Service\Text;

use App\Service\Text\EntryExcerpt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntryExcerptTest extends TestCase
{
    public function testPrefersTheSummaryWhenPresent(): void
    {
        self::assertSame(
            'Summary text.',
            EntryExcerpt::of('Summary text.', '<p>Body text.</p>'),
        );
    }

    public function testFallsBackToTheBodyWhenTheSummaryIsEmpty(): void
    {
        self::assertSame(
            'Body text.',
            EntryExcerpt::of('', '<p>Body text.</p>'),
        );
    }

    public function testFallsBackToTheBodyWhenTheSummaryIsNull(): void
    {
        self::assertSame(
            'Body text.',
            EntryExcerpt::of(null, '<p>Body text.</p>'),
        );
    }

    public function testReturnsAnEmptyStringWhenBothAreEmpty(): void
    {
        self::assertSame('', EntryExcerpt::of(null, null));
    }

    #[DataProvider('nullLeakProvider')]
    public function testFallsBackToTheBodyForANullLeakSummary(string $junk): void
    {
        self::assertSame('Body text.', EntryExcerpt::of($junk, '<p>Body text.</p>'));
    }

    #[DataProvider('nullLeakProvider')]
    public function testFallsThroughANullLeakBodyToAnEmptyString(string $junk): void
    {
        self::assertSame('', EntryExcerpt::of(null, $junk));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nullLeakProvider(): iterable
    {
        yield 'none' => ['none'];
        yield 'null' => ['null'];
        yield 'undefined' => ['undefined'];
        yield 'nil' => ['nil'];
        yield 'n/a' => ['n/a'];
        yield 'dash' => ['-'];
        yield 'em dash' => ['—'];
        yield 'uppercase' => ['NONE'];
    }

    public function testDecodesHtmlEntitiesInTheBody(): void
    {
        self::assertSame(
            'Q&A with the team',
            EntryExcerpt::of(null, 'Q&amp;A with the team'),
        );
    }

    public function testCutsLongTextAtAWordBoundary(): void
    {
        $word = 'lorem ';
        $body = str_repeat($word, 200);

        $excerpt = EntryExcerpt::of(null, $body);

        self::assertLessThanOrEqual(500, mb_strlen($excerpt));
        self::assertSame('lorem', mb_substr($excerpt, -5));
        self::assertStringNotContainsString('  ', $excerpt);
    }

    public function testTruncationIsMultibyteSafe(): void
    {
        $word = 'straße ';
        $body = str_repeat($word, 100);

        $excerpt = EntryExcerpt::of(null, $body);

        // 'ß' is two bytes: a byte-based cut lands mid-character here, unlike
        // a character-based one, so this pins the exact word-boundary result.
        self::assertSame(496, mb_strlen($excerpt));
        self::assertSame(rtrim(str_repeat($word, 71)), $excerpt);
    }

    /**
     * 500 multibyte characters ('é' is 2 bytes) is 999 bytes: a byte-length
     * check would wrongly treat this as over the limit and trim the trailing
     * word off at the last space, where a character-length check leaves it
     * untouched.
     */
    public function testDoesNotTruncateTextAtOrUnderTheCharacterLimitEvenWhenItsByteLengthIsOver(): void
    {
        $body = str_repeat('é', 490) . ' ' . str_repeat('é', 9);

        self::assertSame($body, EntryExcerpt::of(null, $body));
    }

    public function testKeepsTextAtOrUnderTheLimitUnchanged(): void
    {
        $body = str_repeat('a', 500);

        self::assertSame($body, EntryExcerpt::of(null, $body));
    }

    /**
     * No space in the first 500 chars: the hard cut must win, not '' or the
     * full 600.
     */
    public function testFallsBackToAHardCutWhenThereIsNoSpaceToBreakOn(): void
    {
        $expected = str_repeat('a', 500);

        self::assertSame($expected, EntryExcerpt::of(null, str_repeat('a', 600)));
    }

    public function testFallsBackToAHardCutWhenTheBodyIsHtmlWithNoSpaceInTheFirst500Characters(): void
    {
        $expected = str_repeat('a', 500);
        $body = '<p>' . str_repeat('a', 600) . ' with a trailing word</p>';

        self::assertSame($expected, EntryExcerpt::of(null, $body));
    }

    public function testAnInlineImageBecomesAWordBoundary(): void
    {
        self::assertSame(
            'text more',
            EntryExcerpt::of(null, 'text<img src="https://i/a.jpg" alt=""/>more'),
        );
    }
}
