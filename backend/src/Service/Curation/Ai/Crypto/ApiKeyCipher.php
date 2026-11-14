<?php

declare(strict_types=1);

namespace App\Service\Curation\Ai\Crypto;

use App\Entity\SealedSecret;
use App\Service\Foundation\Crypto\InstanceSecretCipher;
use App\Service\Foundation\Crypto\Model\SecretBindingModel;

/** An account's AI provider API key, bound to the account so a row cannot be moved to another one. */
final readonly class ApiKeyCipher
{
    private const string PURPOSE = 'ai-api-key';

    public function __construct(private InstanceSecretCipher $cipher)
    {
    }

    public function seal(int $userId, string $plainApiKey): SealedSecret
    {
        return $this->cipher->seal(SecretBindingModel::forUser(self::PURPOSE, $userId), $plainApiKey);
    }

    public function open(int $userId, SealedSecret $sealed): string
    {
        return $this->cipher->open(SecretBindingModel::forUser(self::PURPOSE, $userId), $sealed);
    }
}
