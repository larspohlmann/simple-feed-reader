<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\ReaderAudit\BodyBlock;
use App\Service\ReaderAudit\ExtractedBody;
use PHPUnit\Framework\TestCase;

final class ExtractedBodyTest extends TestCase
{
    private const string PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle fuer einen '
        . 'Prosa-Block sicher ueberschreitet und damit die Stelle markiert, an der der '
        . 'Artikel beginnt und die Kopfzone endet, und zwar mit genug Zeichen dafuer. ';

    public function testKeepsTheBodyInDocumentOrderWithItsParagraphsHeadingsAndImages(): void
    {
        $body = ExtractedBody::fromHtml(
            '<p>Erster Absatz.</p><p>Zweiter Absatz mit <a href="/x">einem Link</a>.</p>'
            . '<h2>Zwischentitel</h2><img src="https://example.test/a.jpg">',
        );

        self::assertSame(2, $body->paragraphCount);
        self::assertSame(1, $body->headingCount);
        self::assertSame(['https://example.test/a.jpg'], $body->imageSources);
        self::assertSame(
            ['Erster Absatz.', 'Zweiter Absatz mit einem Link.', 'Zwischentitel'],
            array_map(static fn (BodyBlock $block): string => $block->text, $body->blocks),
        );
    }

    public function testABlockThatWrapsOtherBlocksReportsItsChildrenInsteadOfItself(): void
    {
        // Without this, a readability wrapper <div> would report the whole
        // article as one block and the leading region would collapse to nothing.
        $body = ExtractedBody::fromHtml('<div><p>Innen.</p><p>Auch innen.</p></div>');

        self::assertSame(['Innen.', 'Auch innen.'], array_map(
            static fn (BodyBlock $block): string => $block->text,
            $body->blocks,
        ));
    }

    public function testTheLeadingRegionEndsAtTheFirstRealParagraph(): void
    {
        $body = ExtractedBody::fromHtml(
            '<p><a href="/a">Politik</a></p><p><a href="/b">Wirtschaft</a></p>'
            . '<p>' . self::PROSE . '</p><p><a href="/c">Mehr dazu</a></p>',
        );

        self::assertSame(['Politik', 'Wirtschaft'], array_map(
            static fn (BodyBlock $block): string => $block->text,
            $body->leadingBlocks(),
        ));
    }

    public function testABodyThatNeverReachesAParagraphIsLeadingRegionThroughout(): void
    {
        // Such a body is chrome from top to bottom, which is what the rules
        // should then see rather than an empty region they cannot judge.
        $body = ExtractedBody::fromHtml('<p><a href="/a">Politik</a></p><p>Kurz</p>');

        self::assertCount(2, $body->leadingBlocks());
    }

    public function testALongParagraphOfNothingButLinksDoesNotStartTheArticle(): void
    {
        $linked = '<p><a href="/a">' . self::PROSE . '</a></p>';

        self::assertCount(2, ExtractedBody::fromHtml($linked . '<p>Kurz</p>')->leadingBlocks());
    }

    public function testReadsEachLinksTargetSoAShareButtonCanBeRecognisedWithoutItsText(): void
    {
        $body = ExtractedBody::fromHtml('<p><a href="https://x.com/intent/tweet"></a></p>');

        self::assertSame('https://x.com/intent/tweet', $body->links[0]->href);
        self::assertSame('x.com', $body->links[0]->host());
    }

    public function testAnUnparseableBodyMeasuresAsEmptyRatherThanFailing(): void
    {
        $body = ExtractedBody::fromHtml('');

        self::assertSame(0, $body->textLength());
        self::assertSame([], $body->blocks);
        self::assertSame([], $body->leadingBlocks());
    }
}
