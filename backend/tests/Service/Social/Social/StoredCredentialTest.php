<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Social\Crypto\SocialCredentialCipher;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\StoredCredential;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class StoredCredentialTest extends TestCase
{
    public function testTheSealedSecretOpensForItsOwner(): void
    {
        $credential = new StoredCredential(SocialAccounts::cipher());

        self::assertSame(SocialAccounts::APP_PASSWORD, $credential->open(SocialAccounts::bluesky()));
    }

    public function testASecretThatNoLongerOpensReadsAsARefusedCredential(): void
    {
        $rotatedKey = new SocialCredentialCipher(new InstanceSecretCipher(str_repeat('x', 32)));

        $this->expectException(SocialCredentialsRejectedException::class);

        (new StoredCredential($rotatedKey))->open(SocialAccounts::bluesky());
    }
}
