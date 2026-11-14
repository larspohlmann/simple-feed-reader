<?php

declare(strict_types=1);

namespace App\Service\Proxy\Crypto;

use App\Entity\SealedSecret;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Crypto\Model\SecretBindingModel;

/** The instance-wide proxy password; its own binding keeps it apart from every other sealed secret. */
final readonly class ProxyPasswordCipher
{
    private const string PURPOSE = 'proxy-password';

    public function __construct(private InstanceSecretCipher $cipher)
    {
    }

    public function seal(string $plainPassword): SealedSecret
    {
        return $this->cipher->seal(SecretBindingModel::forInstance(self::PURPOSE), $plainPassword);
    }

    public function open(SealedSecret $sealed): string
    {
        return $this->cipher->open(SecretBindingModel::forInstance(self::PURPOSE), $sealed);
    }
}
