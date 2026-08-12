<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\EntryCursor;
use PHPUnit\Framework\TestCase;

final class EntryCursorTest extends TestCase
{
    public function testRoundTripsWithPublishedAt(): void
    {
        $createdAt = new \DateTimeImmutable('2026-07-20T08:30:00+02:00');
        $publishedAt = new \DateTimeImmutable('2026-07-19T10:00:00+02:00');
        $encoded = EntryCursor::encode($createdAt, $publishedAt, 4242);

        $decoded = EntryCursor::decode($encoded);
        self::assertNotNull($decoded);
        self::assertSame($createdAt->getTimestamp(), $decoded->createdAt->getTimestamp());
        self::assertNotNull($decoded->publishedAt);
        self::assertSame($publishedAt->getTimestamp(), $decoded->publishedAt->getTimestamp());
        self::assertSame(4242, $decoded->id);
    }

    public function testRoundTripsWithNullPublishedAt(): void
    {
        $createdAt = new \DateTimeImmutable('2026-07-20T08:30:00+02:00');
        $encoded = EntryCursor::encode($createdAt, null, 4242);

        $decoded = EntryCursor::decode($encoded);
        self::assertNotNull($decoded);
        self::assertSame($createdAt->getTimestamp(), $decoded->createdAt->getTimestamp());
        self::assertNull($decoded->publishedAt);
        self::assertSame(4242, $decoded->id);
    }

    public function testEncodeIsUrlSafeAndOpaque(): void
    {
        $encoded = EntryCursor::encode(new \DateTimeImmutable('2026-01-01T00:00:00Z'), null, 1);
        self::assertSame($encoded, rawurlencode($encoded)); // no +, /, = to escape
        self::assertStringNotContainsString('|', $encoded);
    }

    public function testDecodeRejectsGarbage(): void
    {
        self::assertNull(EntryCursor::decode('not-a-cursor'));
        self::assertNull(EntryCursor::decode(base64_encode('only-one-part')));
        self::assertNull(EntryCursor::decode(base64_encode('bad-date||1')));
        self::assertNull(EntryCursor::decode(base64_encode('2026-01-01T00:00:00+00:00||notint')));
        self::assertNull(EntryCursor::decode(base64_encode('2026-01-01T00:00:00+00:00|bad-date|1')));
        self::assertNull(EntryCursor::decode(''));
    }
}
