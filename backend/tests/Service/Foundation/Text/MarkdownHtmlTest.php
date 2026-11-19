<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Text;

use App\Service\Foundation\Text\MarkdownHtml;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MarkdownHtmlTest extends KernelTestCase
{
    private MarkdownHtml $markdown;

    protected function setUp(): void
    {
        $this->markdown = self::getContainer()->get(MarkdownHtml::class);
    }

    public function testParagraphsEmphasisAndQuotes(): void
    {
        self::assertSame(
            "<p>One</p>\n<p><strong>two</strong></p>\n<blockquote>\n<p>three</p>\n</blockquote>\n",
            $this->markdown->toHtml("One\n\n**two**\n\n> three"),
        );
    }

    public function testALinkKeepsItsAddress(): void
    {
        self::assertSame(
            "<p>See <a href=\"https://example.org/a\">the study</a></p>\n",
            $this->markdown->toHtml('See [the study](https://example.org/a)'),
        );
    }

    public function testRawHtmlIsEscaped(): void
    {
        $html = $this->markdown->toHtml('<script>alert(1)</script> hi');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testAJavascriptLinkLosesItsAddress(): void
    {
        self::assertStringNotContainsString('javascript:', $this->markdown->toHtml('[x](javascript:alert(1))'));
    }

    public function testDeeplyNestedQuotesReturnHtml(): void
    {
        $html = $this->markdown->toHtml(str_repeat('>', 5000) . ' deep');

        self::assertLessThanOrEqual(20, substr_count($html, '<blockquote>'));
        self::assertStringContainsString('deep', $html);
    }

    public function testDeeplyNestedListsReturnHtml(): void
    {
        $nested = '';
        for ($depth = 0; $depth < 5000; ++$depth) {
            $nested .= str_repeat('  ', $depth) . "- item\n";
        }

        $html = $this->markdown->toHtml($nested);

        self::assertLessThanOrEqual(20, substr_count($html, '<ul>'));
        self::assertStringContainsString('item', $html);
    }
}
