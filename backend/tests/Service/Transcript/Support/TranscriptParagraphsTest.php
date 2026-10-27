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

    public function testCuesWithoutSpeakersStayTheirOwnParagraphs(): void
    {
        self::assertCount(2, TranscriptParagraphs::from([
            new TranscriptCueModel(0, null, 'One.'),
            new TranscriptCueModel(3, null, 'Two.'),
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
