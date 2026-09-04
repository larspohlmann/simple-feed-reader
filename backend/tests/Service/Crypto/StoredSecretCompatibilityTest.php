<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Crypto\SealedSecret;
use App\Service\Mail\Settings\Crypto\MailPasswordCipher;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;
use PHPUnit\Framework\TestCase;

/**
 * Rows sealed by the three ciphers before they shared one implementation.
 * Each fixture was produced by the pre-#837 code with this master secret; if
 * any of them stops opening, a deployed database has just lost its secrets.
 */
final class StoredSecretCompatibilityTest extends TestCase
{
    private const string SECRET = 'fixture-instance-secret-key-0123456789abcdef0123456789abcdef';

    private function cipher(): InstanceSecretCipher
    {
        return new InstanceSecretCipher(self::SECRET);
    }

    public function testAnApiKeySealedBeforeTheSharedCipherStillOpens(): void
    {
        $sealed = new SealedSecret(
            'CP4+VukzXD5o2XGf6tg5LfYqPX5sQ+IT0lcxT6uCyKCncw==',
            'YKHWMqDvefpwzY9Ke+yRfP5z0PNjqozO',
            'YT9sy7NWoKyFJ2OwEtRb6Q==',
            1,
        );

        self::assertSame('sk-fixture-api-key', (new ApiKeyCipher($this->cipher()))->open(42, $sealed));
    }

    public function testAProxyPasswordSealedBeforeTheSharedCipherStillOpens(): void
    {
        $sealed = new SealedSecret(
            'Aq2ahkYZRky/gIlFebcwEq29F50DWpS4WveaancMHBQ=',
            'ipaq8n6OG/wKtXIe+6922I/dLDzbALk7',
            'mrcoOsGbkY8chk5mzg508g==',
            1,
        );

        self::assertSame('proxy-fixture-pw', (new ProxyPasswordCipher($this->cipher()))->open($sealed));
    }

    public function testAMailPasswordSealedBeforeTheSharedCipherStillOpens(): void
    {
        $sealed = new SealedSecret(
            'jYS9SR4N+CaeEakbUjxN21Gk5XTUd5kx+vIC/xRFew==',
            'QBUjynNyDPu5m9JpxPrgjsB6ieTGcm8c',
            'TYu0VOtvdzeCy7sSIXQYWA==',
            1,
        );

        self::assertSame('mail-fixture-pw', (new MailPasswordCipher($this->cipher()))->open($sealed));
    }

    public function testTheProxyCipherCannotOpenAMailPassword(): void
    {
        $sealed = (new MailPasswordCipher($this->cipher()))->seal('hunter2');

        $this->expectException(SecretUnreadableException::class);
        (new ProxyPasswordCipher($this->cipher()))->open($sealed);
    }

    public function testTheApiKeyCipherBindsTheAccount(): void
    {
        $sealed = (new ApiKeyCipher($this->cipher()))->seal(42, 'sk-test');

        $this->expectException(SecretUnreadableException::class);
        (new ApiKeyCipher($this->cipher()))->open(43, $sealed);
    }
}
