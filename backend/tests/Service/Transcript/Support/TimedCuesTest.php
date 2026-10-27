<?php

declare(strict_types=1);

namespace App\Tests\Service\Transcript\Support;

use App\Service\Transcript\Model\TranscriptCueModel;
use App\Service\Transcript\Support\TimedCues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimedCuesTest extends TestCase
{
    public function testReadsVttCuesWithTheirVoices(): void
    {
        $cues = TimedCues::of(
            "\u{FEFF}WEBVTT\n\nNOTE made by hand\n\n"
            . "1\n00:00:01.500 --> 00:00:04.000 align:start\n<v Holger Klein>Hallo und</v>\nwillkommen.\n\n"
            . "00:01:05.000 --> 00:01:07.000\n<v.loud Philip>Danke &amp; hallo!\n",
        );

        self::assertEquals(
            [
                new TranscriptCueModel(1, 'Holger Klein', 'Hallo und willkommen.'),
                new TranscriptCueModel(65, 'Philip', 'Danke & hallo!'),
            ],
            $cues,
        );
    }

    public function testReadsShortVttTimingsWithoutHours(): void
    {
        self::assertEquals(
            [new TranscriptCueModel(125, null, 'Zwei Minuten.')],
            TimedCues::of("WEBVTT\n\n02:05.000 --> 02:06.000\nZwei Minuten.\n"),
        );
    }

    public function testReadsSrtCuesAndDropsTheirMarkup(): void
    {
        self::assertEquals(
            [new TranscriptCueModel(3661, null, 'Kursiv hier.')],
            TimedCues::of("1\r\n01:01:01,000 --> 01:01:02,000\r\n<i>Kursiv</i> hier.\r\n"),
        );
    }

    public function testAnAbsurdHourIsNoTimedCueAndRaisesNothing(): void
    {
        self::assertSame(
            [],
            TimedCues::of("WEBVTT\n\n99999999999999999999:00:00.000 --> 00:00:02.000\nToo late.\n"),
        );
    }

    public function testReadsTimingsWithoutMillisecondsOrSpacesAroundTheArrow(): void
    {
        self::assertEquals(
            [new TranscriptCueModel(1, null, 'Bare.'), new TranscriptCueModel(3, null, 'Tight.')],
            TimedCues::of("WEBVTT\n\n00:00:01 --> 00:00:02\nBare.\n\n00:00:03-->00:00:04\nTight.\n"),
        );
    }

    public function testToleratesSurroundingWhitespaceAroundTimingsVoicesAndText(): void
    {
        self::assertEquals(
            [new TranscriptCueModel(7, 'Ann', 'Hi there.')],
            TimedCues::of("WEBVTT\n\n  00:00:07.000 --> 00:00:08.000  \n   <v  Ann >  <b> Hi there. </b>\n"),
        );
    }

    public function testAByteOrderMarkAndLoneCarriageReturnsDoNotHideACue(): void
    {
        self::assertEquals(
            [new TranscriptCueModel(1, null, 'First.'), new TranscriptCueModel(3, null, 'Second.')],
            TimedCues::of("\u{FEFF}00:00:01 --> 00:00:02\rFirst.\r\r00:00:03 --> 00:00:04\rSecond.\r"),
        );
    }

    public function testDecodesQuotesAndApostrophesInCueText(): void
    {
        self::assertEquals(
            [new TranscriptCueModel(1, null, 'It\'s "fine".')],
            TimedCues::of("00:00:01 --> 00:00:02\nIt&apos;s &quot;fine&quot;.\n"),
        );
    }

    public function testAnInlineAllCapsLabelBecomesTheSpeaker(): void
    {
        self::assertEquals(
            [
                new TranscriptCueModel(0, 'JACK', '[Music] Hello.'),
                new TranscriptCueModel(2, 'ALEX HOLDEN', "It's me."),
            ],
            TimedCues::of(
                "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nJACK: [Music] Hello.\n\n"
                . "00:00:02.000 --> 00:00:03.000\nALEX HOLDEN: It's me.\n",
            ),
        );
    }

    public function testASpaceBeforeTheLabelColonIsNotPartOfTheSpeaker(): void
    {
        self::assertEquals(
            [new TranscriptCueModel(0, 'JACK', 'text')],
            TimedCues::of("WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nJACK : text\n"),
        );
    }

    public function testAVoiceTagWinsOverAnInlineLabel(): void
    {
        self::assertEquals(
            [new TranscriptCueModel(0, 'Ann', 'BOB: said.')],
            TimedCues::of("WEBVTT\n\n00:00:00.000 --> 00:00:01.000\n<v Ann>BOB: said.\n"),
        );
    }

    /** @param non-empty-string $text */
    #[DataProvider('notLabels')]
    public function testOtherPrefixesAreNotLabels(string $text): void
    {
        self::assertEquals(
            [new TranscriptCueModel(0, null, $text)],
            TimedCues::of("WEBVTT\n\n00:00:00.000 --> 00:00:01.000\n" . $text . "\n"),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function notLabels(): iterable
    {
        yield 'mixed case' => ['Note: something'];
        yield 'time of day' => ['10:30 is late'];
        yield 'label of 41 characters' => [str_repeat('A', 41) . ': text'];
        yield 'no space after the colon' => ['JACK:text'];
        yield 'label without text' => ['JACK:'];
    }

    public function testALabelOfFortyCharactersIsStillALabel(): void
    {
        $label = str_repeat('A', 40);

        self::assertEquals(
            [new TranscriptCueModel(0, $label, 'text')],
            TimedCues::of("WEBVTT\n\n00:00:00.000 --> 00:00:01.000\n" . $label . ": text\n"),
        );
    }

    public function testACueWithoutTextIsSkipped(): void
    {
        self::assertSame([], TimedCues::of("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n\n"));
    }

    /**
     * logbuch.vtt: NOTE header block and <v> voices; darknet-diaries.vtt: Descript NOTE and voice-less cues;
     * lage-der-nation.vtt: numeric cue identifiers; sample.srt: CRLF, comma timings and <i> markup.
     */
    public function testTheRealFixturesYieldTimedCues(): void
    {
        foreach (['logbuch.vtt', 'darknet-diaries.vtt', 'lage-der-nation.vtt', 'sample.srt'] as $fixture) {
            $cues = TimedCues::of((string) file_get_contents(__DIR__ . '/../../../Fixtures/Transcript/' . $fixture));

            self::assertNotSame([], $cues, $fixture);
            self::assertNotNull($cues[0]->startSeconds, $fixture);
        }
    }
}
