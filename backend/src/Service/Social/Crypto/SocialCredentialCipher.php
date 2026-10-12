<?php

declare(strict_types=1);

namespace App\Service\Social\Crypto;

use App\Entity\SealedSecret;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Crypto\Model\SecretBindingModel;

/** A connected social account's app password or access token, bound to its user. */
final readonly class SocialCredentialCipher
{
    private const string PURPOSE = 'social-account-credential';

    public function __construct(private InstanceSecretCipher $cipher)
    {
    }

    public function seal(int $userId, string $secret): SealedSecret
    {
        return $this->cipher->seal(SecretBindingModel::forUser(self::PURPOSE, $userId), $secret);
    }

    public function open(int $userId, SealedSecret $sealed): string
    {
        return $this->cipher->open(SecretBindingModel::forUser(self::PURPOSE, $userId), $sealed);
    }
}
