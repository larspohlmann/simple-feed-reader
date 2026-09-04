<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings\Crypto;

use App\Service\Mail\Settings\Crypto\Exception\MailPasswordUnreadableException;
use App\Service\Mail\Settings\Crypto\MailPasswordCipher;
use App\Service\Mail\Settings\Crypto\SealedMailPassword;
use PHPUnit\Framework\TestCase;

final class MailPasswordCipherTest extends TestCase
{
    private const SECRET = 'a-test-instance-secret-key-32-chars-min-0123456789';

    public function testSealThenOpenReturnsThePlaintext(): void
    {
        $cipher = new MailPasswordCipher(self::SECRET);
        $sealed = $cipher->seal('hunter2-smtp');

        self::assertSame('hunter2-smtp', $cipher->open($sealed));
    }

    public function testASecondSealOfTheSameValueDiffers(): void
    {
        $cipher = new MailPasswordCipher(self::SECRET);

        self::assertNotSame($cipher->seal('x')->ciphertext, $cipher->seal('x')->ciphertext);
    }

    public function testATamperedCiphertextFailsItsIntegrityCheck(): void
    {
        $cipher = new MailPasswordCipher(self::SECRET);
        $sealed = $cipher->seal('secret');
        $tampered = new SealedMailPassword(
            base64_encode('not the real ciphertext'),
            $sealed->nonce,
            $sealed->salt,
            $sealed->version,
        );

        $this->expectException(MailPasswordUnreadableException::class);
        $cipher->open($tampered);
    }

    public function testASecretOfExactlyTheMinimumLengthIsAccepted(): void
    {
        self::assertSame('x', (new MailPasswordCipher(str_repeat('k', 32)))->open(
            (new MailPasswordCipher(str_repeat('k', 32)))->seal('x'),
        ));
    }

    public function testTooShortASecretIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MailPasswordCipher('too-short');
    }
}
