<?php

declare(strict_types=1);

namespace App\Tests\Pagination;

use App\Pagination\EntryCursor;
use App\Pagination\Exception\MalformedCursorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntryCursorTest extends TestCase
{
    public function testRoundTripsTheSortInstantAndId(): void
    {
        $cursor = EntryCursor::decode(
            EntryCursor::encode(new \DateTimeImmutable('2026-08-14 12:00:00'), 42),
        );

        self::assertSame('2026-08-14 12:00:00', $cursor->sortInstant->format('Y-m-d H:i:s'));
        self::assertSame(42, $cursor->id);
    }

    public function testRejectsAThreePartCursorFromTheOldFormat(): void
    {
        $stale = rtrim(strtr(base64_encode('2026-08-14T12:00:00+00:00||42'), '+/', '-_'), '=');

        $this->expectException(MalformedCursorException::class);

        EntryCursor::decode($stale);
    }

    public function testEncodeIsUrlSafeAndOpaque(): void
    {
        $encoded = EntryCursor::encode(new \DateTimeImmutable('2026-01-01T00:00:00Z'), 1);
        self::assertSame($encoded, rawurlencode($encoded)); // no +, /, = to escape
        self::assertStringNotContainsString('|', $encoded);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedCursors(): iterable
    {
        yield 'not base64' => ['not-a-cursor'];
        yield 'one part' => [base64_encode('only-one-part')];
        yield 'bad date' => [base64_encode('bad-date|1')];
        yield 'non-numeric id' => [base64_encode('2026-01-01T00:00:00+00:00|notint')];
        yield 'empty' => [''];
    }

    #[DataProvider('malformedCursors')]
    public function testDecodeRejectsGarbage(string $cursor): void
    {
        $this->expectException(MalformedCursorException::class);

        EntryCursor::decode($cursor);
    }

    public function testInclusiveUpperBoundAdmitsEveryRealIdAtThatInstant(): void
    {
        $until = new \DateTimeImmutable('2026-07-12T00:00:00Z');

        $cursor = EntryCursor::inclusiveUpperBound($until);

        self::assertSame($until, $cursor->sortInstant);
        self::assertSame(PHP_INT_MAX, $cursor->id);
    }
}
