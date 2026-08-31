<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\ReaderAudit\ExtractedBody;
use PHPUnit\Framework\TestCase;

final class ExtractedBodyTest extends TestCase
{
    public function testCountsTheBlocksParagraphsLinksAndImagesOfACleanedBody(): void
    {
        $body = ExtractedBody::fromHtml(
            '<p>Erster Absatz.</p><p>Zweiter Absatz mit <a href="/x">einem Link</a>.</p>'
            . '<h2>Zwischentitel</h2><img src="https://example.test/a.jpg">',
        );

        self::assertSame(2, $body->paragraphCount);
        self::assertSame(1, $body->headingCount);
        self::assertSame(['einem Link'], $body->linkTexts);
        self::assertSame(['https://example.test/a.jpg'], $body->imageSources);
        self::assertSame(['Erster Absatz.', 'Zweiter Absatz mit einem Link.', 'Zwischentitel'], $body->blockTexts);
    }

    public function testABlockThatWrapsOtherBlocksReportsItsChildrenInsteadOfItself(): void
    {
        // Without this, a readability wrapper <div> would report the whole
        // article as one block and every length-gated phrase rule would go
        // silent on exactly the pages that need them.
        $body = ExtractedBody::fromHtml('<div><p>Innen.</p><p>Auch innen.</p></div>');

        self::assertSame(['Innen.', 'Auch innen.'], $body->blockTexts);
    }

    public function testLinkTextRatioIsTheShareOfCharactersSittingInsideALink(): void
    {
        $body = ExtractedBody::fromHtml('<p><a href="/a">1234</a>5678</p>');

        self::assertSame(0.5, $body->linkTextRatio());
    }

    public function testCountsListItemsThatHoldNothingButALink(): void
    {
        $body = ExtractedBody::fromHtml(
            '<ul><li><a href="/a">Politik</a></li><li><a href="/b">Wirtschaft</a></li>'
            . '<li>Ein Listenpunkt aus echtem Fliesstext ohne Link darin</li></ul>',
        );

        self::assertSame(2, $body->linkDominatedListItems);
    }

    public function testAnUnparseableBodyMeasuresAsEmptyRatherThanFailing(): void
    {
        $body = ExtractedBody::fromHtml('');

        self::assertSame(0, $body->textLength());
        self::assertSame(0.0, $body->linkTextRatio());
        self::assertSame([], $body->blockTexts);
    }
}
