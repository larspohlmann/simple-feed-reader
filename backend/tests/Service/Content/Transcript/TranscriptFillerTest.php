<?php

declare(strict_types=1);

namespace App\Tests\Service\Transcript;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Entity\Feed;
use App\Enum\TranscriptFormat;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Service\Transcript\Exception\TranscriptUnreachableException;
use App\Service\Transcript\Exception\TranscriptUnusableException;
use App\Service\Transcript\TranscriptFiller;
use App\Service\Transcript\TranscriptReader;
use App\Service\Transcript\TranscriptSource\TranscriptSourceInterface;
use App\Tests\Support\TranscriptFixtures;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;

final class TranscriptFillerTest extends TestCase
{
    public function testAReadableBodyFillsTheRow(): void
    {
        $transcript = $this->transcript();

        $this->filler(static fn (): string => TranscriptFixtures::READABLE)->fill($transcript, 10.0);

        self::assertTrue($transcript->isFilled());
        self::assertSame('Hallo.', $transcript->getSearchText());
    }

    public function testAnUnusableAnswerFailsTheRow(): void
    {
        $transcript = $this->transcript();

        $this->filler(static fn (): never => throw TranscriptUnusableException::answered('u', Response::HTTP_NOT_FOUND))
            ->fill($transcript, 10.0);

        self::assertTrue($transcript->isFailed());
    }

    public function testAnUnreadableBodyFailsTheRow(): void
    {
        $transcript = $this->transcript();

        $this->filler(static fn (): string => "WEBVTT\n")->fill($transcript, 10.0);

        self::assertTrue($transcript->isFailed());
    }

    public function testAnUnreachableHostBacksTheRowOff(): void
    {
        $transcript = $this->transcript();

        $this->filler(static fn (): never => throw TranscriptUnreachableException::answered(
            'u',
            Response::HTTP_SERVICE_UNAVAILABLE,
        ))->fill($transcript, 10.0);

        self::assertTrue($transcript->isPending());
        self::assertEquals(new \DateTimeImmutable('2026-10-27 12:30:00'), $transcript->getNextAttemptAt());
    }

    public function testAnUnexpectedFailureBacksTheRowOffInsteadOfEscaping(): void
    {
        $transcript = $this->transcript();

        $this->filler(static fn (): never => throw new \RuntimeException('boom'))->fill($transcript, 10.0);

        self::assertTrue($transcript->isPending());
        self::assertEquals(new \DateTimeImmutable('2026-10-27 12:30:00'), $transcript->getNextAttemptAt());
    }

    #[DataProvider('loggedFailures')]
    public function testAFailureIsLoggedWithItsLevelUrlAndCause(\Throwable $failure, Level $level): void
    {
        $handler = new TestHandler();

        $this->filler(static fn (): never => throw $failure, new Logger('test', [$handler]))
            ->fill($this->transcript(), 10.0);

        self::assertCount(1, $handler->getRecords());
        self::assertSame($level, $handler->getRecords()[0]->level);
        self::assertSame('https://pod.test/ep1.vtt', $handler->getRecords()[0]->context['url']);
        self::assertSame($failure, $handler->getRecords()[0]->context['exception']);
    }

    /** @return iterable<string, array{\Throwable, Level}> */
    public static function loggedFailures(): iterable
    {
        yield 'an unusable answer' => [
            TranscriptUnusableException::answered('u', Response::HTTP_NOT_FOUND),
            Level::Info,
        ];
        yield 'an unreachable host' => [
            TranscriptUnreachableException::answered('u', Response::HTTP_SERVICE_UNAVAILABLE),
            Level::Info,
        ];
        yield 'an unexpected failure' => [new \RuntimeException('boom'), Level::Error];
    }

    public function testTheBudgetReachesTheSource(): void
    {
        $seen = null;
        $this->filler(static function (string $url, float $budget) use (&$seen): string {
            $seen = $budget;

            return TranscriptFixtures::READABLE;
        })->fill($this->transcript(), 3.0);

        self::assertSame(3.0, $seen);
    }

    /** @param \Closure(string, float): string $body */
    private function filler(\Closure $body, ?LoggerInterface $logger = null): TranscriptFiller
    {
        $source = new readonly class ($body) implements TranscriptSourceInterface {
            public function __construct(private \Closure $body)
            {
            }

            public function bodyAt(string $url, float $budgetSeconds): string
            {
                /** @var string $body */
                $body = ($this->body)($url, $budgetSeconds);

                return $body;
            }
        };

        return new TranscriptFiller(
            $source,
            new TranscriptReader(new EntrySanitizer(new TrailingBlankRemover())),
            new NaiveUtcClock(new MockClock('2026-10-27T12:00:00Z')),
            $logger ?? new NullLogger(),
        );
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
