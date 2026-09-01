<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\LeadingEngagementCleaner;
use PHPUnit\Framework\TestCase;

final class LeadingEngagementCleanerTest extends TestCase
{
    private const string PROSE =
        'Hamburg/Norderstedt - Ein Auto floh vor der Polizei und kollidierte mit einem Radfahrer. '
        . 'Die Rettungskräfte brachten den schwer verletzten Mann in ein Krankenhaus. '
        . 'Die Polizei untersucht nun den genauen Ablauf der Verfolgungsfahrt.';

    private LeadingEngagementCleaner $cleaner;

    protected function setUp(): void
    {
        $this->cleaner = new LeadingEngagementCleaner();
    }

    public function testRemovesLeadingEmojiOnlyBlocksIncludingVariationSelectors(): void
    {
        $html = '<div><p>❤️️</p><p>😂️</p><p>' . self::PROSE . '</p></div>';

        $clean = $this->clean($html, null);

        self::assertStringNotContainsString('❤️', $clean);
        self::assertStringNotContainsString('😂', $clean);
        self::assertStringContainsString('Hamburg/Norderstedt', $clean);
    }

    public function testKeepsEmojiInsideAnArticleSentence(): void
    {
        $html = '<div><p>' . self::PROSE . ' Die Zeugin schrieb: ❤️.</p></div>';

        self::assertStringContainsString('❤️', $this->clean($html, null));
    }

    public function testRemovesLeadingCountersWithLocaleSeparatedNumbersAndKnownNouns(): void
    {
        $html = '<div><p>1.251 Klicks</p><p>12,345 views</p><p>1 251 Reaktionen</p>'
            . '<p>' . self::PROSE . '</p></div>';

        $clean = $this->clean($html, null);

        self::assertStringNotContainsString('Klicks', $clean);
        self::assertStringNotContainsString('views', $clean);
        self::assertStringNotContainsString('Reaktionen', $clean);
    }

    public function testKeepsACounterInsideTheArticle(): void
    {
        $html = '<div><p>' . self::PROSE . '</p><h2>3 Kommentare</h2><p>Die Diskussion begann erst später.</p></div>';

        self::assertStringContainsString('3 Kommentare', $this->clean($html, null));
    }

    public function testRemovesALeadingBlockWhoseOnlySemanticContentIsTime(): void
    {
        $html = '<div><p><time datetime="2026-08-31T21:15:00+02:00">31.08.2026 21:15</time></p>'
            . '<p>' . self::PROSE . '</p></div>';

        self::assertStringNotContainsString('31.08.2026', $this->clean($html, null));
    }

    public function testKeepsTimeInsideArticleProse(): void
    {
        $html = '<div><p>' . self::PROSE . ' Um <time>21:15</time> Uhr endete der Einsatz.</p></div>';

        self::assertStringContainsString('21:15', $this->clean($html, null));
    }

    public function testRemovesLeadingBylineWhenTheEntryHasAnAuthor(): void
    {
        $html = '<div><p>Von <a href="https://example.test/jana">Jana Steger</a></p><p>' . self::PROSE . '</p></div>';

        self::assertStringNotContainsString('Von Jana Steger', $this->clean($html, 'Jana Steger'));
    }

    public function testKeepsLeadingBylineWhenTheEntryHasNoAuthor(): void
    {
        $html = '<div><p>By Jane Doe</p><p>' . self::PROSE . '</p></div>';

        self::assertStringContainsString('By Jane Doe', $this->clean($html, null));
    }

    public function testRemovesLeadingEmptyElementsAndHorizontalRulesWithEngagementChrome(): void
    {
        $html = '<section><header><hr></header><div><p>0 reactions</p></div><div></div>'
            . '<p>' . self::PROSE . '</p></section>';

        $clean = $this->clean($html, null);

        self::assertStringNotContainsString('<hr', $clean);
        self::assertStringNotContainsString('reactions', $clean);
        self::assertStringContainsString('Hamburg/Norderstedt', $clean);
    }

    public function testLeavesABodyWithoutARealProseAnchorUnchanged(): void
    {
        $html = '<div><p>❤️️</p><p>0 reactions</p><p><time>21:15</time></p></div>';

        self::assertSame($this->documentHtml($html), $this->clean($html, 'Jana Steger'));
    }

    private function clean(string $html, ?string $entryAuthor): string
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);

        $this->cleaner->removeFrom($document, $entryAuthor);

        return $document->saveHtml();
    }

    private function documentHtml(string $html): string
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);

        return $document->saveHtml();
    }
}
