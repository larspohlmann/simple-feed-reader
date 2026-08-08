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

    public function testJoinsTheContentDeltasOfAStreamedAnswer(): void
    {
        $body = 'data: {"choices":[{"delta":{"role":"assistant"}}]}' . "\n\n"
            . 'data: {"choices":[{"delta":{"content":"{\"recommend"}}]}' . "\n\n"
            . 'data: {"choices":[{"delta":{"content":"ations\":[]}"}}]}' . "\n\n"
            . 'data: [DONE]' . "\n\n";

        self::assertSame('{"recommendations":[]}', $this->decoder->assistantContent($body));
    }

    /**
     * Providers routed through some proxies deliver CRLF line endings; the
     * split must not leave a trailing "\r" glued to the JSON payload.
     */
    public function testACrlfSeparatedStreamDecodesToo(): void
    {
        $body = "data: {\"choices\":[{\"delta\":{\"content\":\"one\"}}]}\r\n\r\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\" two\"}}]}\r\n\r\n"
            . "data: [DONE]\r\n\r\n";

        self::assertSame('one two', $this->decoder->assistantContent($body));
    }

    /**
     * Usage events arrive with an empty choices list and the terminal event
     * is the literal [DONE]; neither may abort the join or contribute text.
     */
    public function testEventsWithoutAContentDeltaAreSkipped(): void
    {
        $body = 'data: {"choices":[{"delta":{"content":"kept"}}]}' . "\n\n"
            . 'data: {"choices":[],"usage":{"total_tokens":9}}' . "\n\n"
            . 'data: [DONE]' . "\n\n";

        self::assertSame('kept', $this->decoder->assistantContent($body));
    }

    public function testAMalformedEventIsSkippedNotFatal(): void
    {
        $body = 'data: not json at all' . "\n\n"
            . 'data: {"choices":[{"delta":{"content":"still here"}}]}' . "\n\n";

        self::assertSame('still here', $this->decoder->assistantContent($body));
    }

    public function testAStreamWithNoContentAtAllIsNull(): void
    {
        $body = 'data: {"choices":[{"delta":{"role":"assistant"}}]}' . "\n\n"
            . 'data: [DONE]' . "\n\n";

        self::assertNull($this->decoder->assistantContent($body));
    }

    /**
     * A provider that ignores `stream: true` and answers with the blocking
     * envelope must keep working — the fallback is the pre-#312 parse.
     */
    public function testABlockingEnvelopeStillDecodes(): void
    {
        $body = '{"choices":[{"message":{"content":"plain answer"}}]}';

        self::assertSame('plain answer', $this->decoder->assistantContent($body));
    }

    public function testABlockingEnvelopeWithoutContentIsNull(): void
    {
        self::assertNull($this->decoder->assistantContent('{"choices":[]}'));
    }

    public function testANonJsonBodyIsNull(): void
    {
        self::assertNull($this->decoder->assistantContent('not json'));
    }
}
