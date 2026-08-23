<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxy\Crypto;

use App\Service\Proxy\Crypto\Exception\ProxyPasswordUnreadableException;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;
use App\Service\Proxy\Crypto\SealedProxyPassword;
use PHPUnit\Framework\TestCase;

final class ProxyPasswordCipherTest extends TestCase
{
    private const SECRET = 'test-master-secret-at-least-32-chars-long!!';

    public function testSealThenOpenRoundTrips(): void
    {
        $cipher = new ProxyPasswordCipher(self::SECRET);
        $sealed = $cipher->seal('super-secret-proxy-pw');

        self::assertNotSame('super-secret-proxy-pw', $sealed->ciphertext);
        self::assertSame('super-secret-proxy-pw', $cipher->open($sealed));
    }

    public function testCiphertextDiffersEachSeal(): void
    {
        $cipher = new ProxyPasswordCipher(self::SECRET);

        self::assertNotSame($cipher->seal('pw')->ciphertext, $cipher->seal('pw')->ciphertext);
    }

    public function testAnotherMasterSecretCannotOpen(): void
    {
        $sealed = (new ProxyPasswordCipher(self::SECRET))->seal('pw');
        $foreign = new ProxyPasswordCipher('another-master-secret-32-chars-minimum!!');

        $this->expectException(ProxyPasswordUnreadableException::class);
        $foreign->open($sealed);
    }

    public function testShortSecretIsRejectedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProxyPasswordCipher('too-short');
    }

    public function testASecretAtExactlyTheMinimumLengthIsAccepted(): void
    {
        $cipher = new ProxyPasswordCipher(str_repeat('a', 32));

        self::assertSame('pw', $cipher->open($cipher->seal('pw')));
    }
}
