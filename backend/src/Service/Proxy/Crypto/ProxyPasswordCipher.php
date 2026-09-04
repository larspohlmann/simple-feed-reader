<?php

declare(strict_types=1);

namespace App\Service\Proxy\Crypto;

use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Crypto\SealedSecret;
use App\Service\Crypto\SecretBinding;

/** The instance-wide proxy password; its own binding keeps it apart from every other sealed secret. */
final readonly class ProxyPasswordCipher
{
    private const string PURPOSE = 'proxy-password';

    public function __construct(private InstanceSecretCipher $cipher)
    {
    }

    public function seal(string $plainPassword): SealedSecret
    {
        return $this->cipher->seal(SecretBinding::forInstance(self::PURPOSE), $plainPassword);
    }

    public function open(SealedSecret $sealed): string
    {
        return $this->cipher->open(SecretBinding::forInstance(self::PURPOSE), $sealed);
    }
}
