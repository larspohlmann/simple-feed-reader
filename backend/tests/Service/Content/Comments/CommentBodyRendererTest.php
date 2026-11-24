<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\Comments;

use App\Service\Content\Comments\CommentBodyRenderer;
use App\Service\Content\Comments\Model\CommentBodyModel;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommentBodyRendererTest extends TestCase
{
    private CommentBodyRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = SocialApiWiring::commentBodyRenderer();
    }

    public function testHtmlIsSanitized(): void
    {
        self::assertSame(
            '<p>Hi <a href="https://example.org" rel="noopener noreferrer" target="_blank">there</a></p>',
            $this->renderer->buildHtml(
                CommentBodyModel::fromHtml('<p>Hi <a href="https://example.org">there</a><script>x</script></p>'),
            ),
        );
    }

    public function testMarkdownIsConvertedThenSanitized(): void
    {
        self::assertSame(
            '<p><strong>bold</strong> &lt;i&gt;</p>',
            $this->renderer->buildHtml(CommentBodyModel::fromMarkdown('**bold** <i>')),
        );
    }

    public function testPlainTextIsEscapedParagraphedAndSanitized(): void
    {
        self::assertSame(
            '<p>a &lt;b&gt;<br />c</p><p>d</p>',
            $this->renderer->buildHtml(CommentBodyModel::fromPlainText("a <b>\nc\n\nd")),
        );
    }

    public function testABareUrlInHtmlBecomesALinkThatOpensInANewTab(): void
    {
        self::assertSame(
            '<p>see <a href="https://example.org/a" rel="noopener noreferrer" target="_blank">'
                . 'https://example.org/a</a>.</p>',
            $this->renderer->buildHtml(CommentBodyModel::fromHtml('<p>see https://example.org/a.</p>')),
        );
    }

    public function testABareUrlInMarkdownBecomesALink(): void
    {
        self::assertStringContainsString(
            '<a href="https://example.org/a"',
            (string) $this->renderer->buildHtml(CommentBodyModel::fromMarkdown('see https://example.org/a')),
        );
    }

    public function testAMarkdownAutolinkIsLinkedOnce(): void
    {
        $html = (string) $this->renderer->buildHtml(CommentBodyModel::fromMarkdown('<https://example.org/a>'));

        self::assertSame(1, substr_count($html, '<a '));
    }

    #[DataProvider('emptyBodies')]
    public function testAnEmptyBodyRendersAsNothing(CommentBodyModel $body): void
    {
        self::assertNull($this->renderer->buildHtml($body));
    }

    /** @return iterable<string, array{CommentBodyModel}> */
    public static function emptyBodies(): iterable
    {
        yield 'blank html' => [CommentBodyModel::fromHtml('  ')];
        yield 'html that sanitizes away' => [CommentBodyModel::fromHtml('<script>x</script>')];
        yield 'blank markdown' => [CommentBodyModel::fromMarkdown("\n")];
        yield 'blank text' => [CommentBodyModel::fromPlainText(' ')];
    }
}
