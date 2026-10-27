<?php

declare(strict_types=1);

namespace App\Tests\Service\Transcript\Support;

use App\Service\Transcript\Model\TranscriptCueModel;
use App\Service\Transcript\Support\TimedCues;
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
