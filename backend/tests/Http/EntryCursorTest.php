<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\EntryCursor;
use PHPUnit\Framework\TestCase;

final class EntryCursorTest extends TestCase
{
    public function testRoundTripsTheEffectiveDateAndId(): void
    {
        $cursor = EntryCursor::decode(
            EntryCursor::encode(new \DateTimeImmutable('2026-08-14 12:00:00'), 42),
        );

        self::assertNotNull($cursor);
        self::assertSame('2026-08-14 12:00:00', $cursor->effectiveDate->format('Y-m-d H:i:s'));
        self::assertSame(42, $cursor->id);
    }

    public function testRejectsAThreePartCursorFromTheOldFormat(): void
    {
        $stale = rtrim(strtr(base64_encode('2026-08-14T12:00:00+00:00||42'), '+/', '-_'), '=');

        self::assertNull(EntryCursor::decode($stale));
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
