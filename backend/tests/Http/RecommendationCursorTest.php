<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\RecommendationCursor;
use PHPUnit\Framework\TestCase;

final class RecommendationCursorTest extends TestCase
{
    public function testRoundTrips(): void
    {
        $encoded = RecommendationCursor::encode(42, 7);

        $decoded = RecommendationCursor::decode($encoded);
        self::assertNotNull($decoded);
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

    // Each case below is named for, and exercises, exactly one of decode()'s
    // four guard branches — see the comment on each for which one and why.

    public function testDecodeRejectsTheEmptyString(): void
    {
        // Hits the `$cursor === ''` guard directly, before base64 is even
        // attempted.
        self::assertNull(RecommendationCursor::decode(''));
    }

    public function testDecodeRejectsInputThatFailsStrictBase64Decoding(): void
    {
        // Spaces and '!' are outside the base64 alphabet, so strict-mode
        // base64_decode() returns false here — the one guard branch
        // ($raw === false) none of the other cases below ever reach, since
        // they are all valid (if meaningless) base64. Confirmed directly:
        //   $ php -r 'var_dump(base64_decode(strtr("not a valid base64!!", "-_", "+/"), true));'
        //   bool(false)
        self::assertNull(RecommendationCursor::decode('not a valid base64!!'));
    }

    public function testDecodeRejectsValidBase64WithNoDelimiter(): void
    {
        // Decodes cleanly but has no '|', so explode() yields a single
        // part — the `count($parts) !== 2` guard.
        self::assertNull(RecommendationCursor::decode(base64_encode('only-one-part')));
    }

    public function testDecodeRejectsValidBase64WithThreeParts(): void
    {
        // Same `count($parts) !== 2` guard, from the other direction: too
        // many delimiters instead of none.
        self::assertNull(RecommendationCursor::decode(base64_encode('1|2|3')));
    }

    public function testDecodeRejectsANonNumericRunId(): void
    {
        // Two well-formed parts, but the first fails ctype_digit().
        self::assertNull(RecommendationCursor::decode(base64_encode('abc|1')));
    }

    public function testDecodeRejectsANonNumericPosition(): void
    {
        // Two well-formed parts, but the second fails ctype_digit().
        self::assertNull(RecommendationCursor::decode(base64_encode('1|abc')));
    }
}
