<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\CompletionBodyDecoder;
use PHPUnit\Framework\TestCase;

final class CompletionBodyDecoderTest extends TestCase
{
    private CompletionBodyDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new CompletionBodyDecoder();
    }

    public function testAnEnvelopeCarriesItsAnswerUnderMessage(): void
    {
        $body = '{"choices":[{"message":{"content":"plain answer"}}]}';

        self::assertSame('plain answer', $this->decoder->envelopeContent($body));
    }

    public function testAStreamEventCarriesItsAnswerUnderDelta(): void
    {
        $payload = '{"choices":[{"delta":{"content":"a fragment"}}]}';

        self::assertSame('a fragment', $this->decoder->deltaContent($payload));
    }

    /**
     * The two keys are the whole difference between the shapes, so each
     * method must refuse the other's. Without this the reader could join an
     * envelope's answer into a stream, or vice versa, and never notice.
     */
    public function testNeitherShapeAcceptsTheOthersKey(): void
    {
        self::assertNull($this->decoder->envelopeContent('{"choices":[{"delta":{"content":"x"}}]}'));
        self::assertNull($this->decoder->deltaContent('{"choices":[{"message":{"content":"x"}}]}'));
    }

    /**
     * A reasoning model's thinking phase arrives as deltas with no `content`
     * at all — the exact traffic that made #320's calls look empty while
     * megabytes flowed. Skipping them is the decoder's job, not the caller's.
     */
    public function testADeltaWithoutContentIsNull(): void
    {
        self::assertNull($this->decoder->deltaContent('{"choices":[{"delta":{"reasoning":"thinking…"}}]}'));
        self::assertNull($this->decoder->deltaContent('{"choices":[{"delta":{"role":"assistant"}}]}'));
    }

    public function testAUsageEventWithoutChoicesIsNull(): void
    {
        self::assertNull($this->decoder->deltaContent('{"choices":[],"usage":{"total_tokens":9}}'));
    }

    public function testAnEnvelopeWithoutContentIsNull(): void
    {
        self::assertNull($this->decoder->envelopeContent('{"choices":[]}'));
    }

    public function testMalformedJsonIsNullRatherThanFatal(): void
    {
        self::assertNull($this->decoder->envelopeContent('not json'));
        self::assertNull($this->decoder->deltaContent('not json at all'));
    }

    /**
     * The provider is untrusted, so every step of the walk can be the wrong
     * type. A non-string content must read as absent, not be coerced.
     */
    public function testANonStringContentIsNull(): void
    {
        self::assertNull($this->decoder->deltaContent('{"choices":[{"delta":{"content":42}}]}'));
        self::assertNull($this->decoder->envelopeContent('{"choices":"nope"}'));
    }
}
