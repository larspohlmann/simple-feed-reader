<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Crypto\SealedSecret;
use App\Service\Crypto\SecretBinding;
use PHPUnit\Framework\TestCase;

/**
 * The properties that make a database dump useless on its own: the master
 * secret is not in the row, the owner and purpose are bound into the
 * ciphertext, and the scheme version is bound with them.
 */
final class InstanceSecretCipherTest extends TestCase
{
    private const string SECRET = 'c0ffee1234567890c0ffee1234567890c0ffee1234567890c0ffee1234567890';

    private function cipher(): InstanceSecretCipher
    {
        return new InstanceSecretCipher(self::SECRET);
    }

    private function binding(int $userId = 42): SecretBinding
    {
        return SecretBinding::forUser('ai-api-key', $userId);
    }

    public function testASealedSecretOpensAgain(): void
    {
        $cipher = $this->cipher();

        $sealed = $cipher->seal($this->binding(), 'sk-test-abcdef');

        self::assertSame('sk-test-abcdef', $cipher->open($this->binding(), $sealed));
    }

    /**
     * A keyless local-server configuration seals an empty string, and
     * duplicateConfiguration() re-opens whatever a source row holds — so this
     * must round-trip exactly like a real key does, not merely be assumed to.
     */
    public function testAnEmptySecretOpensAgainAsAnEmptySecret(): void
    {
        $cipher = $this->cipher();

        $sealed = $cipher->seal($this->binding(), '');

        self::assertSame('', $cipher->open($this->binding(), $sealed));
    }

    public function testTheCiphertextDoesNotContainThePlaintext(): void
    {
        $sealed = $this->cipher()->seal($this->binding(), 'sk-test-abcdef');

        self::assertStringNotContainsString('sk-test-abcdef', base64_decode($sealed->ciphertext, true) ?: '');
    }

    public function testTwoSealsOfOneSecretDifferFromEachOther(): void
    {
        $cipher = $this->cipher();

        $first = $cipher->seal($this->binding(), 'sk-test-abcdef');
        $second = $cipher->seal($this->binding(), 'sk-test-abcdef');

        self::assertNotSame($first->ciphertext, $second->ciphertext);
        self::assertNotSame($first->salt, $second->salt);
    }

    public function testAnotherOwnerCannotOpenTheSecret(): void
    {
        $cipher = $this->cipher();
        $sealed = $cipher->seal($this->binding(42), 'sk-test-abcdef');

        $this->expectException(SecretUnreadableException::class);
        $cipher->open($this->binding(43), $sealed);
    }

    public function testAnotherPurposeCannotOpenTheSecret(): void
    {
        $cipher = $this->cipher();
        $sealed = $cipher->seal(SecretBinding::forInstance('proxy-password'), 'hunter2');

        $this->expectException(SecretUnreadableException::class);
        $cipher->open(SecretBinding::forInstance('mail-password'), $sealed);
    }

    public function testAnAlteredVersionCannotOpenTheSecret(): void
    {
        $cipher = $this->cipher();
        $sealed = $cipher->seal($this->binding(), 'sk-test-abcdef');

        $this->expectException(SecretUnreadableException::class);
        $cipher->open($this->binding(), new SealedSecret($sealed->ciphertext, $sealed->nonce, $sealed->salt, 2));
    }

    public function testAnotherMasterSecretCannotOpenTheSecret(): void
    {
        $sealed = $this->cipher()->seal($this->binding(), 'sk-test-abcdef');
        $other = new InstanceSecretCipher(str_repeat('a', 64));

        $this->expectException(SecretUnreadableException::class);
        $other->open($this->binding(), $sealed);
    }

    public function testATamperedCiphertextCannotOpen(): void
    {
        $cipher = $this->cipher();
        $sealed = $cipher->seal($this->binding(), 'sk-test-abcdef');

        $raw = base64_decode($sealed->ciphertext, true);
        self::assertIsString($raw);
        $raw[0] = $raw[0] === "\x00" ? "\x01" : "\x00";

        $this->expectException(SecretUnreadableException::class);
        $cipher->open(
            $this->binding(),
            new SealedSecret(base64_encode($raw), $sealed->nonce, $sealed->salt, $sealed->version),
        );
    }

    public function testStoredMaterialThatIsNotBase64CannotOpen(): void
    {
        $cipher = $this->cipher();
        $sealed = $cipher->seal($this->binding(), 'sk-test-abcdef');

        $this->expectException(SecretUnreadableException::class);
        $cipher->open($this->binding(), new SealedSecret('not base64 !!', $sealed->nonce, $sealed->salt, 1));
    }

    public function testASecretOfExactlyTheMinimumLengthIsAccepted(): void
    {
        $cipher = new InstanceSecretCipher(str_repeat('k', 32));

        self::assertSame('x', $cipher->open($this->binding(), $cipher->seal($this->binding(), 'x')));
    }

    public function testAShortMasterSecretIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InstanceSecretCipher('too-short');
    }
}
