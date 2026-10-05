<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Support;

use App\Service\Recommendation\Support\ProviderErrorReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderErrorReasonTest extends TestCase
{
    public function testItReadsAnOpenAiStyleErrorMessage(): void
    {
        self::assertSame(
            'Reasoning effort "none" is not supported by this model.',
            ProviderErrorReason::in(
                '{"error":{"message":"Reasoning effort \"none\" is not supported by this model.","code":400},'
                . '"user_id":"user_2abc"}',
            ),
        );
    }

    public function testItReadsADetailString(): void
    {
        self::assertSame('state is too long', ProviderErrorReason::in('{"detail":"state is too long"}'));
    }

    public function testAStructuredDetailIsShownAsCompactJson(): void
    {
        self::assertSame(
            '[{"loc":["body","state"],"msg":"too long"}]',
            ProviderErrorReason::in('{"detail":[{"loc":["body","state"],"msg":"too long"}]}'),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesWithoutAReason(): iterable
    {
        yield 'not json' => ['<html>Bad Request</html>'];
        yield 'empty' => [''];
        yield 'a json string' => ['"nope"'];
        yield 'an error that is a bare string' => ['{"error":"nope"}'];
        yield 'an error without a message' => ['{"error":{"code":400}}'];
        yield 'a message that is not text' => ['{"error":{"message":42}}'];
    }

    #[DataProvider('bodiesWithoutAReason')]
    public function testABodyWithoutAReasonGivesNone(string $body): void
    {
        self::assertNull(ProviderErrorReason::in($body));
    }

    public function testALongReasonIsClippedToFiveHundredCharacters(): void
    {
        $reason = ProviderErrorReason::in(
            json_encode(['error' => ['message' => str_repeat('ä', 501)]], \JSON_THROW_ON_ERROR),
        );

        self::assertSame(str_repeat('ä', 500) . '…', $reason);
    }

    public function testAReasonOfExactlyFiveHundredCharactersIsKeptWhole(): void
    {
        $reason = ProviderErrorReason::in(
            json_encode(['error' => ['message' => str_repeat('a', 500)]], \JSON_THROW_ON_ERROR),
        );

        self::assertSame(str_repeat('a', 500), $reason);
    }
}
