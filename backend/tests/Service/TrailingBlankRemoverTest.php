<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TrailingBlankRemover;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrailingBlankRemoverTest extends TestCase
{
    private TrailingBlankRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new TrailingBlankRemover();
    }

    /** @return iterable<string, array{string, string}> */
    public static function blankTails(): iterable
    {
        $body = '<p>The last sentence.</p>';

        yield 'trailing whitespace and newlines' => [$body . "\n\n  \t", $body];
        yield 'an empty paragraph' => [$body . '<p></p>', $body];
        yield 'a paragraph of non-breaking space' => [$body . '<p>&nbsp;</p>', $body];
        yield 'a numeric non-breaking space' => [$body . '<p>&#160;</p>', $body];
        yield 'a hex non-breaking space' => [$body . '<p>&#xA0;</p>', $body];
        yield 'a literal non-breaking space' => [$body . "\u{00A0}", $body];
        yield 'a bare line break' => [$body . '<br>', $body];
        yield 'a self-closing line break' => [$body . '<br />', $body];
        yield 'a paragraph holding only a line break' => [$body . '<p><br></p>', $body];
        yield 'an empty div' => [$body . '<div></div>', $body];
        yield 'an attributed empty paragraph' => [$body . '<p class="x"></p>', $body];
        yield 'nested empty blocks' => [$body . '<div><p></p></div>', $body];
        yield 'the whole zoo at once' => [
            $body . "\n<p>&nbsp;</p>\n<p></p>\n<br>\n\n  ",
            $body,
        ];
        yield 'a paragraph that ends in a break of its own' => [
            '<p>The last sentence.<br></p>',
            '<p>The last sentence.<br></p>',
        ];
    }

    #[DataProvider('blankTails')]
    public function testRemovesTheBlankTail(string $html, string $expected): void
    {
        self::assertSame($expected, $this->remover->removeFrom($html));
    }

    /**
     * The tail is the only thing that goes. Anything that draws — an image, a
     * rule, a table — is content even when it holds no text, and blanks in the
     * middle of the article are the author's spacing, not junk.
     *
     * @return iterable<string, array{string}>
     */
    public static function untouched(): iterable
    {
        yield 'an article ending in an image' => ['<p>See:</p><p><img src="https://x/i.jpg" alt=""></p>'];
        yield 'an article ending in a rule' => ['<p>Done.</p><hr>'];
        yield 'an article ending in a table' => ['<p>Data:</p><table><tr><td></td></tr></table>'];
        yield 'a blank paragraph in the middle' => ['<p>One.</p><p></p><p>Two.</p>'];
        yield 'a non-breaking space inside a sentence' => ['<p>10&nbsp;km to go.</p>'];
        yield 'plain text with no markup' => ['Just a sentence.'];
    }

    #[DataProvider('untouched')]
    public function testLeavesEverythingElseAlone(string $html): void
    {
        self::assertSame($html, $this->remover->removeFrom($html));
    }

    /**
     * A body that is nothing but blanks trims to nothing — the caller turns that
     * into a null content field rather than storing an empty article.
     */
    public function testABodyOfNothingButBlanksTrimsAway(): void
    {
        self::assertSame('', $this->remover->removeFrom('<p>&nbsp;</p><p></p><br>'));
    }

    /**
     * `preg_replace` answers null on invalid UTF-8. Taking that for an empty
     * result would delete the article instead of its tail, so the input has to
     * come back untouched.
     */
    public function testKeepsInvalidUtf8Exactly(): void
    {
        $invalid = "<p>caf\xE9</p>\n\n";

        self::assertSame($invalid, $this->remover->removeFrom($invalid));
    }
}
