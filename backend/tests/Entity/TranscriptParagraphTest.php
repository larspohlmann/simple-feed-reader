<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Exception\IncompleteStoredMediaException;
use App\Entity\TranscriptParagraph;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranscriptParagraphTest extends TestCase
{
    /** @param array<string, mixed> $stored */
    #[DataProvider('incompleteRows')]
    public function testARowWithoutValidFieldsIsIncomplete(array $stored): void
    {
        self::assertFalse(TranscriptParagraph::isComplete($stored));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function incompleteRows(): iterable
    {
        yield 'text of another type' => [['text' => 5]];
        yield 'text missing' => [['startSeconds' => 1, 'speaker' => 'A']];
        yield 'start of another type' => [['startSeconds' => '1', 'text' => 'x']];
        yield 'speaker of another type' => [['speaker' => 7, 'text' => 'x']];
    }

    /** @param array<string, mixed> $stored */
    #[DataProvider('completeRows')]
    public function testNullStartAndSpeakerAreComplete(array $stored): void
    {
        self::assertTrue(TranscriptParagraph::isComplete($stored));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function completeRows(): iterable
    {
        yield 'explicit nulls' => [['startSeconds' => null, 'speaker' => null, 'text' => 'x']];
        yield 'absent keys' => [['text' => 'x']];
    }

    public function testAnIncompleteRowCannotBeRestored(): void
    {
        $this->expectException(IncompleteStoredMediaException::class);

        TranscriptParagraph::fromStored(['startSeconds' => 3]);
    }

    public function testAParagraphSurvivesItsOwnSerialisation(): void
    {
        $paragraph = new TranscriptParagraph(12, 'Holger', 'Hallo.');

        self::assertEquals($paragraph, TranscriptParagraph::fromStored($paragraph->jsonSerialize()));
        self::assertEquals(
            new TranscriptParagraph(null, null, 'x'),
            TranscriptParagraph::fromStored((new TranscriptParagraph(null, null, 'x'))->jsonSerialize()),
        );
    }
}
