<?php

declare(strict_types=1);

namespace App\Tests\Service\Transcript\Support;

use App\Entity\TranscriptParagraph;
use App\Service\Transcript\Model\TranscriptCueModel;
use App\Service\Transcript\Support\TranscriptParagraphs;
use PHPUnit\Framework\TestCase;

final class TranscriptParagraphsTest extends TestCase
{
    public function testConsecutiveCuesOfOneSpeakerJoinAtTheFirstStart(): void
    {
        self::assertEquals(
            [new TranscriptParagraph(0, 'Ann', 'Hi. How are you?'), new TranscriptParagraph(9, 'Bob', 'Fine.')],
            TranscriptParagraphs::from([
                new TranscriptCueModel(0, 'Ann', 'Hi.'),
                new TranscriptCueModel(4, 'Ann', 'How are you?'),
                new TranscriptCueModel(9, 'Bob', 'Fine.'),
            ]),
        );
    }

    public function testSpeakerlessCuesJoinTheOpenParagraph(): void
    {
        self::assertEquals(
            [new TranscriptParagraph(0, 'Ann', 'One. Two. Three.')],
            TranscriptParagraphs::from([
                new TranscriptCueModel(0, 'Ann', 'One.'),
                new TranscriptCueModel(3, null, 'Two.'),
                new TranscriptCueModel(5, null, 'Three.'),
            ]),
        );
    }

    public function testSpeakerlessCuesWithoutAnyOpenSpeakerShareOneParagraph(): void
    {
        self::assertEquals(
            [new TranscriptParagraph(0, null, 'One. Two.')],
            TranscriptParagraphs::from([
                new TranscriptCueModel(0, null, 'One.'),
                new TranscriptCueModel(3, null, 'Two.'),
            ]),
        );
    }

    public function testADifferentSpeakerAlwaysStartsANewParagraph(): void
    {
        self::assertEquals(
            [new TranscriptParagraph(0, null, 'Intro.'), new TranscriptParagraph(2, 'Ann', 'Hi.')],
            TranscriptParagraphs::from([
                new TranscriptCueModel(0, null, 'Intro.'),
                new TranscriptCueModel(2, 'Ann', 'Hi.'),
            ]),
        );
    }

    public function testAParagraphClosesAtASentenceEndOnceItIsLongAndKeepsItsSpeaker(): void
    {
        $long = str_repeat('word ', 80) . 'end.';

        $paragraphs = TranscriptParagraphs::from([
            new TranscriptCueModel(0, 'Ann', $long),
            new TranscriptCueModel(9, null, 'Next.'),
            new TranscriptCueModel(12, 'Bob', 'Mine.'),
        ]);

        self::assertEquals(
            [
                new TranscriptParagraph(0, 'Ann', $long),
                new TranscriptParagraph(9, 'Ann', 'Next.'),
                new TranscriptParagraph(12, 'Bob', 'Mine.'),
            ],
            $paragraphs,
        );
    }

    public function testAnUnpunctuatedSpeakerlessRunClosesOnceItPassesTwoThousandCharacters(): void
    {
        $cues = [];
        for ($index = 0; $index < 250; $index++) {
            $cues[] = new TranscriptCueModel($index, null, 'caption words here');
        }

        $paragraphs = TranscriptParagraphs::from($cues);

        self::assertCount(3, $paragraphs);
        foreach ($paragraphs as $paragraph) {
            self::assertLessThanOrEqual(2000 + 19, mb_strlen($paragraph->text));
        }
        self::assertSame(
            250 * 18 + 247,
            array_sum(array_map(
                static fn (TranscriptParagraph $paragraph): int => mb_strlen($paragraph->text),
                $paragraphs,
            )),
        );
        self::assertSame([0, 106, 212], array_map(
            static fn (TranscriptParagraph $paragraph): ?int => $paragraph->startSeconds,
            $paragraphs,
        ));
    }

    public function testAParagraphClosesExactlyAtTwoThousandCharacters(): void
    {
        $paragraphs = TranscriptParagraphs::from([
            new TranscriptCueModel(0, null, str_repeat('a', 2000)),
            new TranscriptCueModel(5, null, 'b'),
        ]);

        self::assertCount(2, $paragraphs);
    }

    public function testAParagraphOneCharacterUnderTwoThousandStaysOpen(): void
    {
        $paragraphs = TranscriptParagraphs::from([
            new TranscriptCueModel(0, null, str_repeat('a', 1999)),
            new TranscriptCueModel(5, null, 'b'),
        ]);

        self::assertCount(1, $paragraphs);
    }

    public function testASpeakerlessRunBelowTwoThousandCharactersWithoutASentenceEndStaysOneParagraph(): void
    {
        $paragraphs = TranscriptParagraphs::from([
            new TranscriptCueModel(0, null, str_repeat('word ', 300)),
            new TranscriptCueModel(5, null, 'more words'),
        ]);

        self::assertCount(1, $paragraphs);
    }

    public function testASpeakerOnTheCueAfterAClosedParagraphWinsOverTheCarriedOne(): void
    {
        $long = str_repeat('word ', 80) . 'end.';

        self::assertEquals(
            [new TranscriptParagraph(0, 'Ann', $long), new TranscriptParagraph(9, 'Bob', 'Mine.')],
            TranscriptParagraphs::from([
                new TranscriptCueModel(0, 'Ann', $long),
                new TranscriptCueModel(9, 'Bob', 'Mine.'),
            ]),
        );
    }

    public function testTheClosingLengthCountsCharactersNotBytes(): void
    {
        self::assertCount(1, TranscriptParagraphs::from([
            new TranscriptCueModel(0, null, str_repeat('é', 200) . '.'),
            new TranscriptCueModel(1, null, 'Next.'),
        ]));
    }

    public function testALongParagraphEndingInAClosingQuoteClosesToo(): void
    {
        $long = str_repeat('word ', 80) . 'end."';

        self::assertCount(2, TranscriptParagraphs::from([
            new TranscriptCueModel(0, null, $long),
            new TranscriptCueModel(9, null, 'Next.'),
        ]));
    }

    public function testAParagraphDoesNotCloseBeforeItIsLongOrOutsideASentenceEnd(): void
    {
        self::assertCount(1, TranscriptParagraphs::from([
            new TranscriptCueModel(0, null, 'Short.'),
            new TranscriptCueModel(1, null, str_repeat('word ', 90) . 'and'),
            new TranscriptCueModel(2, null, 'more.'),
        ]));
    }

    public function testAParagraphOfExactlyFourHundredCharactersClosesAtItsSentenceEnd(): void
    {
        $exact = str_repeat('a', 399) . '.';

        self::assertCount(2, TranscriptParagraphs::from([
            new TranscriptCueModel(0, null, $exact),
            new TranscriptCueModel(1, null, 'Next.'),
        ]));
        self::assertCount(1, TranscriptParagraphs::from([
            new TranscriptCueModel(0, null, substr($exact, 1)),
            new TranscriptCueModel(1, null, 'Next.'),
        ]));
    }

    public function testTheSearchTextNamesEachSpeakerOnce(): void
    {
        self::assertSame(
            "Ann: Hi.\nUntimed.",
            TranscriptParagraphs::searchTextOf([
                new TranscriptParagraph(0, 'Ann', 'Hi.'),
                new TranscriptParagraph(null, null, 'Untimed.'),
            ]),
        );
    }
}
