<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Social\Model\TextSpanModel;
use App\Service\Social\Support\BlueskyTextSpans;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueskyTextSpansTest extends TestCase
{
    public function testSpansAreAddressedInBytesNotCharacters(): void
    {
        $text = 'é @ann.bsky.social see https://x.test/a #tag';

        self::assertEquals([new TextSpanModel(3, 19, 'ann.bsky.social')], BlueskyTextSpans::mentions($text));
        self::assertEquals([new TextSpanModel(24, 40, 'https://x.test/a')], BlueskyTextSpans::links($text));
        self::assertEquals([new TextSpanModel(41, 45, 'tag')], BlueskyTextSpans::tags($text));
    }

    #[DataProvider('links')]
    public function testALinkLosesTheTrailingPunctuationOfTheSentence(string $text, string $link): void
    {
        self::assertSame([$link], array_map(
            static fn (TextSpanModel $span): string => $span->value,
            BlueskyTextSpans::links($text),
        ));
    }

    /** @return iterable<string, array{string, string}> */
    public static function links(): iterable
    {
        yield 'full stop' => ['see https://x.test/a.', 'https://x.test/a'];
        yield 'question' => ['https://x.test/a?', 'https://x.test/a'];
        yield 'in parentheses' => ['(https://x.test/a)', 'https://x.test/a'];
        yield 'balanced parenthesis' => ['https://x.test/wiki/A_(b)', 'https://x.test/wiki/A_(b)'];
        yield 'query kept' => ['https://x.test/a?b=1', 'https://x.test/a?b=1'];
    }

    #[DataProvider('noTags')]
    public function testOnlyAWordWithALetterAfterTheHashIsATag(string $text): void
    {
        self::assertSame([], BlueskyTextSpans::tags($text));
    }

    /** @return iterable<string, array{string}> */
    public static function noTags(): iterable
    {
        yield 'digits only' => ['#123'];
        yield 'inside a word' => ['a#tag'];
        yield 'punctuation only' => ['#!!'];
        yield 'too long' => ['#' . str_repeat('a', 65)];
    }

    public function testATagLosesTrailingPunctuationAndAcceptsTheFullWidthHash(): void
    {
        self::assertEquals(
            [new TextSpanModel(0, 5, 'news'), new TextSpanModel(7, 15, 'café')],
            BlueskyTextSpans::tags('#news, ＃café'),
        );
    }

    #[DataProvider('noMentions')]
    public function testOnlyAHandleWithADomainAfterSpaceIsAMention(string $text): void
    {
        self::assertSame([], BlueskyTextSpans::mentions($text));
    }

    /** @return iterable<string, array{string}> */
    public static function noMentions(): iterable
    {
        yield 'an e-mail address' => ['mail ann@bsky.social'];
        yield 'no domain' => ['@ann'];
        yield 'numeric top level' => ['@ann.123'];
    }

    public function testAMentionIsLowerCasedAndMayFollowAParenthesis(): void
    {
        self::assertEquals(
            [new TextSpanModel(1, 17, 'ann.bsky.social')],
            BlueskyTextSpans::mentions('(@Ann.Bsky.Social)'),
        );
    }
}
