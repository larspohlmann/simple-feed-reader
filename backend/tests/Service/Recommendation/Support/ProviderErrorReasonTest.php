<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Support;

use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Support\ProviderErrorReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderErrorReasonTest extends TestCase
{
    public function testItReadsAnOpenAiStyleErrorMessage(): void
    {
        self::assertSame(
            'Reasoning effort "none" is not supported by this model.',
            self::reasonIn(
                '{"error":{"message":"Reasoning effort \"none\" is not supported by this model.","code":400},'
                . '"user_id":"user_2abc"}',
            ),
        );
    }

    public function testItReadsADetailString(): void
    {
        self::assertSame('state is too long', self::reasonIn('{"detail":"state is too long"}'));
    }

    public function testAStructuredDetailIsShownAsCompactJson(): void
    {
        self::assertSame(
            '[{"loc":["body","state"],"msg":"too long"}]',
            self::reasonIn('{"detail":[{"loc":["body","state"],"msg":"too long"}]}'),
        );
    }

    public function testAStructuredDetailKeepsSlashesAndNonAsciiCharactersUnescaped(): void
    {
        self::assertSame(
            '[{"loc":["body","a/b"],"msg":"zu lang für Ärger"}]',
            self::reasonIn('{"detail":[{"loc":["body","a\/b"],"msg":"zu lang f\u00fcr \u00c4rger"}]}'),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesWithoutAReason(): iterable
    {
        yield 'not json' => ['Bad Request'];
        yield 'empty' => [''];
        yield 'a json string' => ['"nope"'];
        yield 'an error that is a bare string' => ['{"error":"nope"}'];
        yield 'an error without a message' => ['{"error":{"code":400}}'];
        yield 'a message that is not text' => ['{"error":{"message":42}}'];
    }

    #[DataProvider('bodiesWithoutAReason')]
    public function testABodyWithoutAReasonGivesNone(string $body): void
    {
        self::assertNull(self::reasonIn($body));
    }

    /** @return iterable<string, array{string, string}> */
    public static function reasonsAroundTheClip(): iterable
    {
        yield 'exactly five hundred characters is kept whole' => [str_repeat('a', 500), str_repeat('a', 500)];
        yield 'one more is clipped by character' => [str_repeat('ä', 501), str_repeat('ä', 500) . '…'];
    }

    #[DataProvider('reasonsAroundTheClip')]
    public function testAReasonIsClippedAfterFiveHundredCharacters(string $message, string $expected): void
    {
        self::assertSame(
            $expected,
            self::reasonIn(json_encode(['error' => ['message' => $message]], \JSON_THROW_ON_ERROR)),
        );
    }

    public function testTheApiKeyIsRedactedFromTheReason(): void
    {
        self::assertSame('Bad key [redacted].', self::reasonIn('{"detail":"Bad key sk-secret-key."}'));
    }

    public function testAKeyStraddlingTheClipIsRedactedWhole(): void
    {
        $message = str_repeat('x', 490) . 'sk-secret-key and more';

        $reason = self::reasonIn(json_encode(['error' => ['message' => $message]], \JSON_THROW_ON_ERROR));

        self::assertSame(str_repeat('x', 490) . '[redacted]…', $reason);
    }

    private static function reasonIn(string $body): ?string
    {
        return ProviderErrorReason::in(
            $body,
            ProviderCredentialsModel::fromStoredConfiguration('https://llm.example.test/v1', 'sk-secret-key'),
        );
    }
}
