<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Crypto;

use App\Service\Foundation\Crypto\Exception\SecretUnreadableException;
use App\Service\Foundation\Crypto\InstanceSecretCipher;
use App\Service\Social\Social\Crypto\SocialCredentialCipher;
use PHPUnit\Framework\TestCase;

final class SocialCredentialCipherTest extends TestCase
{
    private function cipher(): SocialCredentialCipher
    {
        return new SocialCredentialCipher(new InstanceSecretCipher(str_repeat('k', 32)));
    }

    public function testASealedCredentialOpensForItsOwner(): void
    {
        $sealed = $this->cipher()->seal(7, 'abcd-efgh-ijkl-mnop');

        self::assertSame('abcd-efgh-ijkl-mnop', $this->cipher()->open(7, $sealed));
    }

    public function testASealedCredentialDoesNotOpenForAnotherUser(): void
    {
        $sealed = $this->cipher()->seal(7, 'abcd-efgh-ijkl-mnop');

        $this->expectException(SecretUnreadableException::class);

        $this->cipher()->open(8, $sealed);
    }
}
