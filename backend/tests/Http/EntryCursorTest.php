<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\EntryCursor;
use PHPUnit\Framework\TestCase;

final class EntryCursorTest extends TestCase
{
    public function testRoundTrips(): void
    {
        $date = new \DateTimeImmutable('2026-07-20T08:30:00+02:00');
        $encoded = EntryCursor::encode($date, 4242);

        $decoded = EntryCursor::decode($encoded);
        self::assertNotNull($decoded);
        self::assertSame($date->getTimestamp(), $decoded->date->getTimestamp());
        self::assertSame(4242, $decoded->id);
    }

    public function testEncodeIsUrlSafeAndOpaque(): void
    {
        $encoded = EntryCursor::encode(new \DateTimeImmutable('2026-01-01T00:00:00Z'), 1);
        self::assertSame($encoded, rawurlencode($encoded)); // no +, /, = to escape
        self::assertStringNotContainsString('|', $encoded);
    }

    public function testDecodeRejectsGarbage(): void
    {
        self::assertNull(EntryCursor::decode('not-a-cursor'));
        self::assertNull(EntryCursor::decode(base64_encode('only-one-part')));
        self::assertNull(EntryCursor::decode(base64_encode('bad-date|1')));
        self::assertNull(EntryCursor::decode(base64_encode('2026-01-01T00:00:00+00:00|notint')));
        self::assertNull(EntryCursor::decode(''));
    }
}
