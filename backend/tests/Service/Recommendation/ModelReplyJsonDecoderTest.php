<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\ModelReplyJsonDecoder;
use PHPUnit\Framework\TestCase;

final class ModelReplyJsonDecoderTest extends TestCase
{
    private ModelReplyJsonDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new ModelReplyJsonDecoder();
    }

    public function testPlainJsonObjectDecodes(): void
    {
        self::assertSame(['a' => 1], $this->decoder->decode('{"a": 1}'));
    }

    public function testFencedJsonWithLanguageTagDecodes(): void
    {
        self::assertSame(['a' => 1], $this->decoder->decode("```json\n{\"a\": 1}\n```"));
    }

    public function testFencedJsonWithoutLanguageTagDecodes(): void
    {
        self::assertSame(['a' => 1], $this->decoder->decode("```\n{\"a\": 1}\n```"));
    }

    public function testSurroundingWhitespaceIsTrimmedBeforeFenceDetection(): void
    {
        self::assertSame(['a' => 1], $this->decoder->decode("  \n```json\n{\"a\": 1}\n```\n  "));
    }

    public function testFenceClosingImmediatelyAfterTheJsonIsStripped(): void
    {
        self::assertSame(['a' => 1], $this->decoder->decode("```json\n{\"a\": 1}```"));
    }

    public function testClosingFenceWithoutAnOpeningFenceIsNotStripped(): void
    {
        self::assertNull($this->decoder->decode('{"a": 1}```'));
    }

    public function testNonJsonReturnsNull(): void
    {
        self::assertNull($this->decoder->decode('not json'));
    }

    public function testScalarJsonReturnsNull(): void
    {
        self::assertNull($this->decoder->decode('42'));
    }
}
