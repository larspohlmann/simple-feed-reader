<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\LeadingEngagementCleaner;
use App\Service\ReaderAudit\CleanupMarker;
use App\Service\ReaderAudit\ExtractedBody;
use App\Service\ReaderAudit\LeadingEngagementMarkers;
use PHPUnit\Framework\TestCase;

final class LeadingEngagementMarkersTest extends TestCase
{
    private const string PROSE =
        'Hamburg/Norderstedt - Ein Auto floh vor der Polizei und kollidierte mit einem Radfahrer. '
        . 'Die Rettungskräfte brachten den schwer verletzten Mann in ein Krankenhaus. '
        . 'Die Polizei untersucht nun den genauen Ablauf der Verfolgungsfahrt.';

    private LeadingEngagementMarkers $markers;

    protected function setUp(): void
    {
        $this->markers = new LeadingEngagementMarkers();
    }

    public function testReportsTheIssueEngagementChromeBeforeCleanupAndNothingAfter(): void
    {
        $html = '<section><header><hr></header><div><p><time>31.08.2026 21:15</time></p></div>'
            . '<div><p>1.251 Klicks</p><p>0 Reaktionen</p></div><div><p>❤️️</p><p>😂️</p></div>'
            . '<p>Von Jana Steger</p><p>' . self::PROSE . '</p></section>';

        self::assertSame(['leading_engagement_chrome'], $this->codesFor($html, 'Jana Steger'));
        self::assertSame([], $this->codesFor($this->clean($html, 'Jana Steger'), 'Jana Steger'));
    }

    public function testKeepsTheMarkerOutOfAnArticleCounter(): void
    {
        $html = '<p>' . self::PROSE . '</p><h2>3 Kommentare</h2><p>Die Diskussion begann erst später.</p>';

        self::assertSame([], $this->codesFor($html, null));
    }

    public function testReportsTheNestedLeadingChromeFromEntry494422(): void
    {
        $html = '<div><section><article><header><hr></header><section>'
            . '<div><p class="lead"><time>01.09.2026 18:39</time></p></div>'
            . '<div><div><p><span>❤️️</span></p><p><span>😂️</span></p><p><span>😱️</span></p>'
            . '<p><span>🔥️</span></p><p><span>😥️</span></p><p><span>👏️</span></p></div></div>'
            . '<p>Frischer Wind in Hamburg: Ab Herbst spielt Erik Hamilton die Hauptrolle im "MJ - Das Michael Jackson '
            . 'Musical" in Hamburg.</p><p>Von Svenja-Marie Kahl</p><p>Hamburg - Überraschung für alle Musical-Fans: '
            . '"MJ - Das Michael Jackson Musical" in Hamburg bekommt einen neuen Hauptdarsteller. ' . self::PROSE
            . '</p></section></article></section></div>';

        self::assertSame(['leading_engagement_chrome'], $this->codesFor($html, 'Svenja-Marie Kahl'));
        self::assertSame([], $this->codesFor($this->clean($html, 'Svenja-Marie Kahl'), 'Svenja-Marie Kahl'));
    }

    public function testReportsWeightSuspectAndTheFirstThreeBlocksWithAnEllipsis(): void
    {
        $html = '<div><p><time>31.08.2026 21:15</time></p><p>1.251 Klicks</p><p>0 Reaktionen</p>'
            . '<p>❤️️</p><p>😂️</p><p>Von Jana Steger</p><p>' . self::PROSE . '</p></div>';

        $markers = $this->markers->detect(ExtractedBody::fromHtml($html), 'Jana Steger');

        self::assertCount(1, $markers);
        self::assertSame(3, $markers[0]->weight);
        self::assertSame('LeadingEngagementCleaner', $markers[0]->suspect);
        self::assertSame(
            '6 engagement blocks before the article: "31.08.2026 21:15" | "1.251 Klicks" | "0 Reaktionen" | …',
            $markers[0]->detail,
        );
    }

    public function testReportsALeadingTimeOnlyBlockOnItsOwn(): void
    {
        $html = '<div><p><time>31.08.2026 21:15</time></p><p>' . self::PROSE . '</p></div>';

        self::assertSame(['leading_engagement_chrome'], $this->codesFor($html, 'Jana Steger'));
    }

    public function testReportsALeadingBylineOnlyWhenTheEntryHasAnAuthor(): void
    {
        $html = '<div><p>Von Jana Steger</p><p>' . self::PROSE . '</p></div>';

        self::assertSame(['leading_engagement_chrome'], $this->codesFor($html, 'Jana Steger'));
        self::assertSame([], $this->codesFor($html, null));
    }

    /** @return list<string> */
    private function codesFor(string $html, ?string $entryAuthor): array
    {
        return array_map(
            static fn (CleanupMarker $marker): string => $marker->code,
            $this->markers->detect(ExtractedBody::fromHtml($html), $entryAuthor),
        );
    }

    private function clean(string $html, ?string $entryAuthor): string
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);
        (new LeadingEngagementCleaner())->removeFrom($document, $entryAuthor);

        return $document->saveHtml();
    }
}
