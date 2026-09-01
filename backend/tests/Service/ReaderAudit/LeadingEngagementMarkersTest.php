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
