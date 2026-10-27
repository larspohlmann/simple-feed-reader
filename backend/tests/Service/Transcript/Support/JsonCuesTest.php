<?php

declare(strict_types=1);

namespace App\Tests\Service\Transcript\Support;

use App\Service\Transcript\Exception\TranscriptUnusableException;
use App\Service\Transcript\Model\TranscriptCueModel;
use App\Service\Transcript\Support\JsonCues;
use PHPUnit\Framework\TestCase;

final class JsonCuesTest extends TestCase
{
    public function testReadsSegmentsWithSpeakersAndFlooredStarts(): void
    {
        self::assertEquals(
            [new TranscriptCueModel(0, 'Ann', 'Hi.'), new TranscriptCueModel(12, null, 'Yes.')],
            JsonCues::of(
                '{"version":"1.0.0","segments":[{"speaker":"Ann","startTime":0.5,"endTime":2,"body":" Hi. "},'
                . '{"startTime":12.9,"body":"Yes."},{"startTime":13,"body":"  "}]}',
            ),
        );
    }

    public function testOnlyFiniteStartsWithinRangeAreKept(): void
    {
        $cues = JsonCues::of('{"segments":[{"startTime":-1,"body":"A"},{"startTime":1e30,"body":"B"},'
            . '{"startTime":3599999,"body":"C"},{"startTime":3600000,"body":"D"},{"startTime":"5","body":"E"}]}');

        self::assertSame([null, null, 3599999, null, null], array_map(
            static fn (TranscriptCueModel $cue): ?int => $cue->startSeconds,
            $cues,
        ));
    }

    public function testAStartAtZeroIsKept(): void
    {
        self::assertEquals(
            [new TranscriptCueModel(0, null, 'Opening.')],
            JsonCues::of('{"segments":[{"startTime":0,"body":"Opening."}]}'),
        );
    }

    public function testSpeakersAreTrimmedAndBlankOnesDropped(): void
    {
        self::assertEquals(
            [new TranscriptCueModel(1, 'Ann', 'A.'), new TranscriptCueModel(2, null, 'B.')],
            JsonCues::of(
                '{"segments":[{"speaker":"  Ann ","startTime":1,"body":"A."},'
                . '{"speaker":"   ","startTime":2,"body":"B."}]}',
            ),
        );
    }

    public function testMalformedJsonIsUnusable(): void
    {
        $this->expectException(TranscriptUnusableException::class);
        JsonCues::of('{"segments":');
    }

    public function testADocumentWithoutSegmentsHasNoCues(): void
    {
        self::assertSame([], JsonCues::of('{"chapters":[]}'));
    }

    public function testTheSampleFixtureKeepsItsSpeakers(): void
    {
        $cues = JsonCues::of((string) file_get_contents(__DIR__ . '/../../../Fixtures/Transcript/sample.json'));

        self::assertSame(
            ['Ann', 'Ann', 'Bob'],
            array_map(static fn (TranscriptCueModel $cue): ?string => $cue->speaker, $cues),
        );
    }
}
