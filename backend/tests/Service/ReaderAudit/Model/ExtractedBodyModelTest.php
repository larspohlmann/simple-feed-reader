<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit\Model;

use App\Service\ReaderAudit\Model\BodyBlockModel;
use App\Service\ReaderAudit\Model\ExtractedBodyModel;
use PHPUnit\Framework\TestCase;

final class ExtractedBodyModelTest extends TestCase
{
    public function testKeepsTheBodyInDocumentOrderWithItsParagraphsHeadingsAndImages(): void
    {
        $body = ExtractedBodyModel::fromHtml(
            '<p>Erster Absatz.</p><p>Zweiter Absatz mit <a href="/x">einem Link</a>.</p>'
            . '<h2>Zwischentitel</h2><img src="https://example.test/a.jpg">',
        );

        self::assertSame(2, $body->paragraphCount);
        self::assertSame(1, $body->headingCount);
        self::assertSame(['https://example.test/a.jpg'], $body->imageSources);
        self::assertSame(
            ['Erster Absatz.', 'Zweiter Absatz mit einem Link.', 'Zwischentitel'],
            array_map(static fn (BodyBlockModel $block): string => $block->text, $body->blocks),
        );
    }

    public function testABlockThatWrapsOtherBlocksReportsItsChildrenInsteadOfItself(): void
    {
        // Without this, a readability wrapper <div> would report the whole
        // article as one block and the leading region would collapse to nothing.
        $body = ExtractedBodyModel::fromHtml('<div><p>Innen.</p><p>Auch innen.</p></div>');

        self::assertSame(['Innen.', 'Auch innen.'], array_map(
            static fn (BodyBlockModel $block): string => $block->text,
            $body->blocks,
        ));
    }

    public function testReadsEachLinksTargetSoAShareButtonCanBeRecognisedWithoutItsText(): void
    {
        $body = ExtractedBodyModel::fromHtml('<p><a href="https://x.com/intent/tweet"></a></p>');

        self::assertSame('https://x.com/intent/tweet', $body->links[0]->href);
        self::assertSame('x.com', $body->links[0]->host());
    }

    public function testABlockIsLinkDominatedAtEightyPercentOfItsCharacters(): void
    {
        $block = static fn (int $linked): string => '<p><a href="/a">' . str_repeat('a', $linked) . '</a>'
            . str_repeat('b', 100 - $linked) . '</p>';
        $exactly = ExtractedBodyModel::fromHtml($block(80));
        $justUnder = ExtractedBodyModel::fromHtml($block(79));

        self::assertTrue($exactly->blocks[0]->isLinkDominated());
        self::assertFalse($justUnder->blocks[0]->isLinkDominated());
    }

    public function testAnEmptyBlockIsNotLinkDominated(): void
    {
        // Division by its own length would throw, and calling it chrome would
        // make every stray empty element a menu entry.
        $body = ExtractedBodyModel::fromHtml('<p><a href="/a"></a></p><p>Text</p>');

        self::assertSame(['Text'], array_map(static fn (BodyBlockModel $b): string => $b->text, $body->blocks));
    }

    public function testHasArticleTextAnswersWhetherThePageYieldedAnArticleAtAll(): void
    {
        $notice = ExtractedBodyModel::fromHtml('<p>' . str_repeat('a', 1199) . '</p>');
        $article = ExtractedBodyModel::fromHtml('<p>' . str_repeat('a', 1200) . '</p>');

        self::assertFalse($notice->hasArticleText());
        self::assertTrue($article->hasArticleText());
    }

    public function testAnAnchorIntoThePagesOwnSectionsDoesNotLeaveThePage(): void
    {
        // An article's table of contents. Counting its entries as navigation
        // reported deutschlandfunk.de's own long-read format as chrome (#744).
        $body = ExtractedBodyModel::fromHtml('<li><a href="#kapitel">Regelfall Einzelzimmer</a></li>');

        self::assertSame(0, $body->blocks[0]->outboundLinks());
        self::assertFalse($body->links[0]->leavesThePage());
    }

    public function testAnAnchorWithNoTargetAtAllDoesNotLeaveThePageEither(): void
    {
        $body = ExtractedBodyModel::fromHtml('<li><a>Regelfall Einzelzimmer</a></li>');

        self::assertSame(0, $body->blocks[0]->outboundLinks());
    }

    public function testCountsTheLinksInEachBlockSeparatelyFromTheBodysOwn(): void
    {
        $body = ExtractedBodyModel::fromHtml('<p><a href="/a">eins</a> und <a href="/b">zwei</a></p>');

        self::assertCount(2, $body->blocks[0]->links);
        self::assertSame(2, $body->blocks[0]->outboundLinks());
        self::assertSame(['eins', 'zwei'], array_map(static fn ($l): string => $l->text, $body->blocks[0]->links));
        self::assertSame(13, $body->blocks[0]->length());
    }

    public function testCollapsesRunsOfWhitespaceSoAWrappedMenuEntryReadsAsOneLine(): void
    {
        $body = ExtractedBodyModel::fromHtml("<p>  Zwei \n\t Woerter  </p>");

        self::assertSame('Zwei Woerter', $body->blocks[0]->text);
        self::assertSame('Zwei Woerter', $body->text);
    }

    public function testALinkWithNoHrefReadsAsAnEmptyTargetRatherThanFailing(): void
    {
        $body = ExtractedBodyModel::fromHtml('<p><a>kein Ziel</a></p>');

        self::assertSame('', $body->links[0]->href);
        self::assertSame('', $body->links[0]->host());
    }

    public function testReportsTheBlocksTagSoAListItemCanBeToldFromAParagraph(): void
    {
        $body = ExtractedBodyModel::fromHtml('<ul><li>Eins</li></ul><p>Zwei</p>');

        self::assertSame(['li', 'p'], array_map(static fn (BodyBlockModel $b): string => $b->tag, $body->blocks));
    }

    public function testCountsHeadingsOfEveryLevel(): void
    {
        $body = ExtractedBodyModel::fromHtml('<h1>a</h1><h2>b</h2><h3>c</h3><h4>d</h4><h5>e</h5><h6>f</h6>');

        self::assertSame(6, $body->headingCount);
    }

    public function testAnUnparseableBodyMeasuresAsEmptyRatherThanFailing(): void
    {
        $body = ExtractedBodyModel::fromHtml('');

        self::assertSame(0, $body->textLength());
        self::assertSame([], $body->blocks);
    }
}
