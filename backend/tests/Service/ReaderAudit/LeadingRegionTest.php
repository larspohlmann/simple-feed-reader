<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\ReaderAudit\LeadingRegion;
use App\Service\ReaderAudit\Model\BodyBlockModel;
use App\Service\ReaderAudit\Model\ExtractedBodyModel;
use App\Tests\Support\AuditMarkers;
use PHPUnit\Framework\TestCase;

final class LeadingRegionTest extends TestCase
{
    private const string PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle fuer einen '
        . 'Prosa-Block sicher ueberschreitet und damit die Stelle markiert, an der der '
        . 'Artikel beginnt und die Kopfzone endet, und zwar mit genug Zeichen dafuer. ';

    private LeadingRegion $region;

    protected function setUp(): void
    {
        $this->region = AuditMarkers::leadingRegion();
    }

    public function testTheLeadingRegionEndsAtTheFirstRealParagraph(): void
    {
        $body = ExtractedBodyModel::fromHtml(
            '<p><a href="/a">Politik</a></p><p><a href="/b">Wirtschaft</a></p>'
            . '<p>' . self::PROSE . '</p><p><a href="/c">Mehr dazu</a></p>',
        );

        self::assertSame(['Politik', 'Wirtschaft'], array_map(
            static fn (BodyBlockModel $block): string => $block->text,
            $this->region->blocksOf($body),
        ));
    }

    public function testABodyThatNeverReachesAParagraphIsLeadingRegionThroughout(): void
    {
        // Such a body is chrome from top to bottom, which is what the rules
        // should then see rather than an empty region they cannot judge.
        $body = ExtractedBodyModel::fromHtml('<p><a href="/a">Politik</a></p><p>Kurz</p>');

        self::assertCount(2, $this->region->blocksOf($body));
    }

    public function testALongParagraphOfNothingButLinksDoesNotStartTheArticle(): void
    {
        $linked = '<p><a href="/a">' . self::PROSE . '</a></p>';

        self::assertCount(2, $this->region->blocksOf(ExtractedBodyModel::fromHtml($linked . '<p>Kurz</p>')));
    }

    public function testAParagraphOfExactlyOneHundredAndTwentyCharactersStartsTheArticle(): void
    {
        $atLimit = '<p>' . str_repeat('a', 120) . '</p><p>Kurz</p>';
        $justUnder = '<p>' . str_repeat('a', 119) . '</p><p>Kurz</p>';

        self::assertSame([], $this->region->blocksOf(ExtractedBodyModel::fromHtml($atLimit)));
        self::assertCount(2, $this->region->blocksOf(ExtractedBodyModel::fromHtml($justUnder)));
    }

    public function testProseLengthCountsCharactersNotBytes(): void
    {
        // These umlauts are twice as many bytes as characters; counting bytes
        // would call a short caption the start of the article and empty the
        // leading region.
        $umlauts = '<p>' . str_repeat('ä', 119) . '</p><p>Kurz</p>';

        self::assertCount(2, $this->region->blocksOf(ExtractedBodyModel::fromHtml($umlauts)));
    }

    public function testAnUnparseableBodyHasNoLeadingRegion(): void
    {
        self::assertSame([], $this->region->blocksOf(ExtractedBodyModel::fromHtml('')));
    }
}
