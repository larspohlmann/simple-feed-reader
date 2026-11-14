<?php

declare(strict_types=1);

namespace App\Service\Social\Crypto;

use App\Entity\SealedSecret;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Crypto\Model\SecretBindingModel;

final readonly class BlueskySessionCipher
{
    private const string PURPOSE = 'bluesky-session';

    public function __construct(private InstanceSecretCipher $cipher)
    {
    }

    public function seal(int $userId, string $accessJwt): SealedSecret
    {
        return $this->cipher->seal(SecretBindingModel::forUser(self::PURPOSE, $userId), $accessJwt);
    }

    public function open(int $userId, SealedSecret $sealed): string
    {
        return $this->cipher->open(SecretBindingModel::forUser(self::PURPOSE, $userId), $sealed);
    }
}
