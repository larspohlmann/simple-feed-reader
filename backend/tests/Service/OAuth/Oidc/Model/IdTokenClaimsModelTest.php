<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth\Oidc\Model;

use App\Service\OAuth\Exception\OAuthFailedException;
use App\Service\OAuth\Oidc\Model\IdTokenClaimsModel;
use App\Service\OAuth\Oidc\Model\IdTokenModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shape reader: accessors return null for a wrong-typed claim, so {@see IdTokenVerifier} words each rejection.
 */
final class IdTokenClaimsModelTest extends TestCase
{
    public function testAWellFormedPayloadIsDecoded(): void
    {
        $claims = IdTokenClaimsModel::decode($this->token(['sub' => 'sub-123']));

        self::assertSame('sub-123', $claims->string('sub'));
    }

    public function testAPayloadIsDecodedAsBase64UrlNotPlainBase64(): void
    {
        // `>>>???` encodes to both `-` and `_` (asserted below), so a plain-alphabet decoder cannot pass by luck.
        $value = '>>>???';
        $token = $this->token(['sub' => $value]);
        self::assertStringContainsString('-', $token->jwt);
        self::assertStringContainsString('_', $token->jwt);

        self::assertSame($value, IdTokenClaimsModel::decode($token)->string('sub'));
    }

    public function testAnUnpaddedPayloadIsDecoded(): void
    {
        // JWTs are minted with the base64 padding stripped, so a decoder that
        // insisted on `=` would reject every real token.
        $encoded = rtrim($this->encode('{"sub":"abc"}'), '=');
        self::assertStringEndsNotWith('=', $encoded);

        $claims = IdTokenClaimsModel::decode(new IdTokenModel('header.' . $encoded . '.signature'));

        self::assertSame('abc', $claims->string('sub'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'no separators' => ['notajwt'];
        yield 'two segments' => ['header.payload'];
        yield 'four segments' => ['header.payload.signature.extra'];
        yield 'empty' => [''];
    }

    #[DataProvider('malformedTokens')]
    public function testATokenThatIsNotThreeSegmentsIsRejected(string $jwt): void
    {
        $this->assertRejectedWith('id_token is not a three-segment JWT', new IdTokenModel($jwt));
    }

    public function testAPayloadThatIsNotBase64IsRejected(): void
    {
        $this->assertRejectedWith(
            'id_token payload is not valid base64url',
            new IdTokenModel('header.!!!not base64!!!.signature'),
        );
    }

    public function testAPayloadThatIsNotJsonIsRejected(): void
    {
        $this->assertRejectedWith(
            'id_token payload is not valid JSON',
            $this->tokenFromPayload('not json'),
        );
    }

    public function testAPayloadThatIsNotAnObjectIsRejected(): void
    {
        $this->assertRejectedWith(
            'id_token payload is not a JSON object',
            $this->tokenFromPayload('"just a string"'),
        );
    }

    public function testAJsonArrayPayloadIsRejected(): void
    {
        // A JSON array decodes to a PHP array too, so an is_array() check alone
        // would wave it through to the claim reads and fail there by luck.
        $this->assertRejectedWith(
            'id_token payload is a JSON array, not an object',
            $this->tokenFromPayload('["iss","aud"]'),
        );
    }

    /** Asserts the reason reached $logDetail: the caller's message is one sentence for every OAuth failure. */
    private function assertRejectedWith(string $logDetail, IdTokenModel $token): void
    {
        try {
            IdTokenClaimsModel::decode($token);
        } catch (OAuthFailedException $exception) {
            self::assertSame($logDetail, $exception->logDetail);
            self::assertStringNotContainsString($logDetail, $exception->getMessage());

            return;
        }

        self::fail('expected the decode to be rejected');
    }

    public function testEveryDecodeFailureIsAnOAuthFailure(): void
    {
        // The caller turns these into one indistinguishable redirect, which
        // only holds if nothing here escapes as a different exception type.
        $this->expectException(OAuthFailedException::class);

        IdTokenClaimsModel::decode(new IdTokenModel('nope'));
    }

    public function testStringReadsOnlyStrings(): void
    {
        $claims = IdTokenClaimsModel::decode($this->token([
            'text' => 'a value',
            'number' => 42,
            'list' => ['a'],
            'boolean' => true,
        ]));

        self::assertSame('a value', $claims->string('text'));
        self::assertNull($claims->string('number'));
        self::assertNull($claims->string('list'));
        self::assertNull($claims->string('boolean'));
        self::assertNull($claims->string('absent'));
    }

    public function testIntReadsOnlyJsonNumbersNotNumericStrings(): void
    {
        // RFC 7519 defines `exp` as a JSON number. A token spelling it "1780"
        // was not built by the provider, and coercing it would mean accepting a
        // shape only a forger produces.
        $claims = IdTokenClaimsModel::decode($this->token([
            'number' => 1780000000,
            'numeric string' => '1780000000',
            'float' => 1.5,
        ]));

        self::assertSame(1780000000, $claims->int('number'));
        self::assertNull($claims->int('numeric string'));
        self::assertNull($claims->int('float'));
        self::assertNull($claims->int('absent'));
    }

    public function testStringListReadsTheTwoShapesRfc7519AllowsForAudience(): void
    {
        $claims = IdTokenClaimsModel::decode($this->token([
            'single' => 'one',
            'many' => ['one', 'two'],
        ]));

        self::assertSame(['one'], $claims->stringList('single'));
        self::assertSame(['one', 'two'], $claims->stringList('many'));
    }

    public function testStringListDropsMembersThatAreNotStrings(): void
    {
        // There must be no shape of `aud` whose members can match by accident,
        // so a nested array or a number simply is not a candidate.
        $claims = IdTokenClaimsModel::decode($this->token([
            'mixed' => ['one', ['nested'], 42, null, 'two'],
            'nested only' => [['one']],
        ]));

        self::assertSame(['one', 'two'], $claims->stringList('mixed'));
        self::assertSame([], $claims->stringList('nested only'));
    }

    public function testStringListIsEmptyForAnythingElse(): void
    {
        $claims = IdTokenClaimsModel::decode($this->token(['number' => 42]));

        self::assertSame([], $claims->stringList('number'));
        self::assertSame([], $claims->stringList('absent'));
    }

    public function testClaimHandsBackTheRawValueForClaimsWhoseShapeIsThePolicy(): void
    {
        // `email_verified` is read raw on purpose: which spellings count as
        // verified is a trust decision, and it belongs with the other trust
        // decisions in the verifier rather than in this shape reader.
        $claims = IdTokenClaimsModel::decode($this->token([
            'email_verified' => 'true',
            'other' => ['a'],
        ]));

        self::assertSame('true', $claims->claim('email_verified'));
        self::assertSame(['a'], $claims->claim('other'));
        self::assertNull($claims->claim('absent'));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function token(array $claims): IdTokenModel
    {
        return $this->tokenFromPayload(json_encode($claims, \JSON_THROW_ON_ERROR));
    }

    private function tokenFromPayload(string $payload): IdTokenModel
    {
        return new IdTokenModel('header.' . $this->encode($payload) . '.signature');
    }

    private function encode(string $bytes): string
    {
        return strtr(base64_encode($bytes), '+/', '-_');
    }
}
