<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Jev\Support;

use App\Service\Recommendation\Jev\Support\SystemOneReplyDecoder;
use PHPUnit\Framework\TestCase;

final class SystemOneReplyDecoderTest extends TestCase
{
    /** OpenRouter names the call by its generation id in the body and prices it in `usage.cost`. */
    public function testOpenRoutersReplyGivesItsGenerationIdItsVersionAndItsPrice(): void
    {
        $reply = SystemOneReplyDecoder::decode(
            '{"id":"gen-1759400000-abc","model":"typesafe/jev-1.13-20260917",'
            . '"answers":{"entry-7":{"type":"noul","noul":0.5}},'
            . '"usage":{"prompt_tokens":410,"completion_tokens":12,"cost":0.00042}}',
            null,
        );

        self::assertSame('gen-1759400000-abc', $reply->receipt->requestId);
        self::assertSame('typesafe/jev-1.13-20260917', $reply->receipt->answeringModel);
        $usage = $reply->receipt->usage;
        self::assertNotNull($usage);
        self::assertSame(420_000, $usage->costNanoCredits);
        self::assertSame(410, $usage->promptTokens);
        self::assertSame(12, $usage->completionTokens);
    }

    public function testTypeSafesHeaderIdWinsOverTheBodysId(): void
    {
        $reply = SystemOneReplyDecoder::decode('{"id":"gen-from-body","answers":{}}', 'req-from-header');

        self::assertSame('req-from-header', $reply->receipt->requestId);
    }

    /** Only a string question id with a numeric Noul is an answer; the parser rejects a batch missing one. */
    public function testAnAnswerWithoutAStringIdOrANumericNoulIsLeftOut(): void
    {
        $reply = SystemOneReplyDecoder::decode(
            '{"answers":{"entry-7":{"type":"noul","noul":"high"},"entry-9":{"type":"noul","noul":1},"entry-11":"x"}}',
            null,
        );
        $listed = SystemOneReplyDecoder::decode('{"answers":[{"type":"noul","noul":0.4}]}', null);

        self::assertSame(['entry-9' => 1.0], $reply->nouls);
        self::assertSame([], $listed->nouls);
    }

    /** A negative count would subtract from the run's total, which the repository adds in SQL. */
    public function testANegativeTokenCountReadsAsZero(): void
    {
        $reply = SystemOneReplyDecoder::decode('{"usage":{"input_tokens":-5,"output_tokens":7}}', null);

        $usage = $reply->receipt->usage;
        self::assertNotNull($usage);
        self::assertSame(0, $usage->promptTokens);
        self::assertSame(7, $usage->completionTokens);
    }
}
