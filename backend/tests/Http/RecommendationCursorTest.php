<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Exception\MalformedCursorException;
use App\Http\RecommendationCursor;
use PHPUnit\Framework\TestCase;

final class RecommendationCursorTest extends TestCase
{
    public function testRoundTrips(): void
    {
        $encoded = RecommendationCursor::encode(42, 7);

        $decoded = RecommendationCursor::decode($encoded);
        self::assertSame(42, $decoded->runId);
        self::assertSame(7, $decoded->position);
    }

    public function testEncodeIsUrlSafeAndOpaque(): void
    {
        $encoded = RecommendationCursor::encode(1, 1);
        self::assertSame($encoded, rawurlencode($encoded)); // no +, /, = to escape
        self::assertStringNotContainsString('|', $encoded);
    }

    public function testEncodeStripsBase64Padding(): void
    {
        // "1|12" is 4 raw bytes, which base64-encodes with trailing '=' padding
        // that rtrim() must strip — unlike "1|1" above, whose 3 raw bytes
        // happen to need no padding at all and so cannot exercise the rtrim.
        $encoded = RecommendationCursor::encode(1, 12);
        self::assertStringNotContainsString('=', $encoded);
        self::assertSame($encoded, rawurlencode($encoded));
    }

    public function testDecodeRejectsTheEmptyString(): void
    {
        $this->expectException(MalformedCursorException::class);

        RecommendationCursor::decode('');
    }

    public function testDecodeRejectsInputThatFailsStrictBase64Decoding(): void
    {
        // Spaces and '!' are outside the alphabet, so strict base64_decode() returns false.
        $this->expectException(MalformedCursorException::class);

        RecommendationCursor::decode('not a valid base64!!');
    }

    public function testDecodeRejectsValidBase64WithNoDelimiter(): void
    {
        $this->expectException(MalformedCursorException::class);

        RecommendationCursor::decode(base64_encode('only-one-part'));
    }

    public function testDecodeRejectsValidBase64WithThreeParts(): void
    {
        $this->expectException(MalformedCursorException::class);

        RecommendationCursor::decode(base64_encode('1|2|3'));
    }

    public function testDecodeRejectsANonNumericRunId(): void
    {
        $this->expectException(MalformedCursorException::class);

        RecommendationCursor::decode(base64_encode('abc|1'));
    }

    public function testDecodeRejectsANonNumericPosition(): void
    {
        $this->expectException(MalformedCursorException::class);

        RecommendationCursor::decode(base64_encode('1|abc'));
    }
}
