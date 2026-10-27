<?php

declare(strict_types=1);

namespace App\Tests\Service\Transcript;

use App\Entity\TranscriptParagraph;
use App\Enum\TranscriptFormat;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Service\Transcript\Exception\TranscriptUnusableException;
use App\Service\Transcript\TranscriptReader;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $paragraphs = $this->reader()->read($body, TranscriptFormat::Html)->paragraphs;

        self::assertSame('Jerod Santo:', $paragraphs[0]->text);
        self::assertStringStartsWith(
            'What up, nerds? I\'m Jerod and this is Changelog News for the week of Monday, February 16th, 2026. '
            . 'OpenClaw creator, Peter Steinberger, announced on Saturday',
            $paragraphs[1]->text,
        );
        self::assertStringEndsWith('Ok, let\'s get into this week\'s news.', $paragraphs[1]->text);
    }

    public function testInvalidUtf8BytesDoNotDropTheCue(): void
    {
        $read = $this->reader()->read(
            "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nbad \xC3\x28 utf8\n",
            TranscriptFormat::Vtt,
        );

        self::assertCount(1, $read->paragraphs);
    }

    /** @param array{0: string, 1: TranscriptFormat} $input */
    #[DataProvider('bodiesWithoutText')]
    public function testBodiesWithoutCuesAreUnusable(array $input): void
    {
        $this->expectException(TranscriptUnusableException::class);
        $this->reader()->read($input[0], $input[1]);
    }

    /** @return iterable<string, array{array{string, TranscriptFormat}}> */
    public static function bodiesWithoutText(): iterable
    {
        yield 'json without segments' => [['{"segments":[]}', TranscriptFormat::Json]];
        yield 'json array root' => [['[1,2]', TranscriptFormat::Json]];
        yield 'empty html' => [['', TranscriptFormat::Html]];
        yield 'empty html paragraph' => [['<p></p>', TranscriptFormat::Html]];
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
