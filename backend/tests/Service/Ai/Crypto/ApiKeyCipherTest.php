<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Crypto;

use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Crypto\Exception\ApiKeyUnreadableException;
use App\Service\Ai\Crypto\SealedApiKey;
use PHPUnit\Framework\TestCase;

/**
 * The properties that make a database dump useless on its own: the master
 * secret is not in the row, the account id is bound into the ciphertext, and
 * the scheme version is bound with it.
 */
final class ApiKeyCipherTest extends TestCase
{
    private const string SECRET = 'c0ffee1234567890c0ffee1234567890c0ffee1234567890c0ffee1234567890';

    private function cipher(): ApiKeyCipher
    {
        return new ApiKeyCipher(self::SECRET);
    }

    public function testASealedKeyOpensAgain(): void
    {
        $cipher = $this->cipher();

        $sealed = $cipher->seal(42, 'sk-test-abcdef');

        self::assertSame('sk-test-abcdef', $cipher->open(42, $sealed));
    }

    public function testTheCiphertextDoesNotContainThePlainKey(): void
    {
        $sealed = $this->cipher()->seal(42, 'sk-test-abcdef');

        self::assertStringNotContainsString('sk-test-abcdef', base64_decode($sealed->ciphertext, true) ?: '');
    }

    public function testTwoSealsOfOneKeyDifferFromEachOther(): void
    {
        $cipher = $this->cipher();

        $first = $cipher->seal(42, 'sk-test-abcdef');
        $second = $cipher->seal(42, 'sk-test-abcdef');

        self::assertNotSame($first->ciphertext, $second->ciphertext);
        self::assertNotSame($first->salt, $second->salt);
    }

    public function testAnotherAccountCannotOpenTheKey(): void
    {
        $cipher = $this->cipher();
        $sealed = $cipher->seal(42, 'sk-test-abcdef');

        $this->expectException(ApiKeyUnreadableException::class);
        $cipher->open(43, $sealed);
    }

    public function testAnAlteredVersionCannotOpenTheKey(): void
    {
        $cipher = $this->cipher();
        $sealed = $cipher->seal(42, 'sk-test-abcdef');

        $this->expectException(ApiKeyUnreadableException::class);
        $cipher->open(42, new SealedApiKey($sealed->ciphertext, $sealed->nonce, $sealed->salt, 2));
    }

    public function testAnotherMasterSecretCannotOpenTheKey(): void
    {
        $sealed = $this->cipher()->seal(42, 'sk-test-abcdef');
        $other = new ApiKeyCipher(str_repeat('a', 64));

        $this->expectException(ApiKeyUnreadableException::class);
        $other->open(42, $sealed);
    }

    public function testATamperedCiphertextCannotOpen(): void
    {
        $cipher = $this->cipher();
        $sealed = $cipher->seal(42, 'sk-test-abcdef');

        $raw = base64_decode($sealed->ciphertext, true);
        self::assertIsString($raw);
        $raw[0] = $raw[0] === "\x00" ? "\x01" : "\x00";

        $this->expectException(ApiKeyUnreadableException::class);
        $cipher->open(42, new SealedApiKey(base64_encode($raw), $sealed->nonce, $sealed->salt, $sealed->version));
    }

    public function testStoredMaterialThatIsNotBase64CannotOpen(): void
    {
        $cipher = $this->cipher();
        $sealed = $cipher->seal(42, 'sk-test-abcdef');

        $this->expectException(ApiKeyUnreadableException::class);
        $cipher->open(42, new SealedApiKey('not base64 !!', $sealed->nonce, $sealed->salt, $sealed->version));
    }

    public function testAShortMasterSecretIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ApiKeyCipher('too-short');
    }
}
