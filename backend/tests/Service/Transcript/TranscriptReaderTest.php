<?php

declare(strict_types=1);

namespace App\Tests\Service\Transcript;

use App\Entity\TranscriptParagraph;
use App\Enum\TranscriptFormat;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Service\Transcript\Exception\TranscriptUnusableException;
use App\Service\Transcript\TranscriptReader;
use PHPUnit\Framework\TestCase;

final class TranscriptReaderTest extends TestCase
{
    public function testAVttBodyBecomesParagraphsAndSearchText(): void
    {
        $read = $this->reader()->read(
            "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\n<v Ann>Hi.\n\n00:00:01.000 --> 00:00:02.000\n<v Ann>Again.\n",
            TranscriptFormat::Vtt,
        );

        self::assertEquals([new TranscriptParagraph(0, 'Ann', 'Hi. Again.')], $read->paragraphs);
        self::assertSame('Ann: Hi. Again.', $read->searchText);
    }

    public function testAnHtmlBodyIsSanitizedIntoUntimedParagraphs(): void
    {
        $read = $this->reader()->read(
            '<p>First <b>words</b>.</p><script>alert(1)</script><p>Second.</p>',
            TranscriptFormat::Html,
        );

        self::assertEquals(
            [new TranscriptParagraph(null, null, 'First words.'), new TranscriptParagraph(null, null, 'Second.')],
            $read->paragraphs,
        );
    }

    public function testTheChangelogFixtureReadsAsHtml(): void
    {
        $body = (string) file_get_contents(__DIR__ . '/../../Fixtures/Transcript/changelog.html');

        self::assertNotSame([], $this->reader()->read($body, TranscriptFormat::Html)->paragraphs);
    }

    public function testABodyWithoutTextIsUnusable(): void
    {
        $this->expectException(TranscriptUnusableException::class);
        $this->reader()->read("WEBVTT\n\n", TranscriptFormat::Vtt);
    }

    private function reader(): TranscriptReader
    {
        return new TranscriptReader(new EntrySanitizer(new TrailingBlankRemover()));
    }
}
