<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Entity\Feed;
use App\Entity\TranscriptParagraph;
use App\Enum\TranscriptFormat;
use PHPUnit\Framework\TestCase;

final class EntryTranscriptTest extends TestCase
{
    public function testANewTranscriptIsPendingAndDueAtOnce(): void
    {
        $transcript = $this->transcript();

        self::assertTrue($transcript->isPending());
        self::assertSame([], $transcript->getParagraphs());
        self::assertNull($transcript->getSearchText());
        self::assertNull($transcript->getNextAttemptAt());
    }

    public function testItHasNoIdBeforeItIsFlushed(): void
    {
        $this->expectException(\LogicException::class);

        $this->transcript()->requireId();
    }

    public function testFillingStoresTheParagraphsAndTheSearchText(): void
    {
        $transcript = $this->transcript();
        $paragraphs = [new TranscriptParagraph(0, 'Holger', 'Hallo.'), new TranscriptParagraph(null, null, 'Ende.')];

        $transcript->fill($paragraphs, "Holger: Hallo.\nEnde.");

        self::assertTrue($transcript->isFilled());
        self::assertEquals($paragraphs, $transcript->getParagraphs());
        self::assertSame("Holger: Hallo.\nEnde.", $transcript->getSearchText());
    }

    public function testATransientFailureBacksOffAndStaysPending(): void
    {
        $transcript = $this->transcript();
        $now = new \DateTimeImmutable('2026-10-27 12:00:00');

        $transcript->retryAfter($now);

        self::assertTrue($transcript->isPending());
        self::assertEquals(new \DateTimeImmutable('2026-10-27 12:30:00'), $transcript->getNextAttemptAt());

        $transcript->retryAfter($now);

        self::assertEquals(new \DateTimeImmutable('2026-10-27 13:00:00'), $transcript->getNextAttemptAt());
    }

    public function testTheFifthFailedAttemptGivesUp(): void
    {
        $transcript = $this->transcript();
        $now = new \DateTimeImmutable('2026-10-27 12:00:00');

        for ($attempt = 1; $attempt < EntryTranscript::MAX_ATTEMPTS; $attempt++) {
            $transcript->retryAfter($now);
            self::assertTrue($transcript->isPending());
        }
        $transcript->retryAfter($now);

        self::assertTrue($transcript->isFailed());
        self::assertNull($transcript->getNextAttemptAt());
    }

    public function testFailingIsFinal(): void
    {
        $transcript = $this->transcript();
        $transcript->retryAfter(new \DateTimeImmutable('2026-10-27 12:00:00'));

        $transcript->fail();

        self::assertTrue($transcript->isFailed());
        self::assertFalse($transcript->isPending());
        self::assertNull($transcript->getNextAttemptAt());
    }

    private function transcript(): EntryTranscript
    {
        $entry = new Entry(
            new Feed('https://pod.test/feed.xml'),
            'guid-1',
            'https://pod.test/ep1',
            'Episode 1',
            new \DateTimeImmutable('2026-10-27 06:00:00'),
            new \DateTimeImmutable('2026-10-27 05:00:00'),
        );

        return new EntryTranscript($entry, 'https://pod.test/ep1.vtt', TranscriptFormat::Vtt, 'de');
    }
}
