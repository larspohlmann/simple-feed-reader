<?php

declare(strict_types=1);

namespace App\Tests\Pagination;

use App\Pagination\Exception\MalformedCursorException;
use App\Pagination\RecommendationCursor;
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

    public function testEncodesThePairAsUnpaddedBase64Url(): void
    {
        self::assertSame('MXwxMg', RecommendationCursor::encode(1, 12));
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
