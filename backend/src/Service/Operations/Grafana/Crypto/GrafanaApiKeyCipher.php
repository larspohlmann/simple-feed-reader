<?php

declare(strict_types=1);

namespace App\Service\Grafana\Crypto;

use App\Entity\SealedSecret;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Crypto\Model\SecretBindingModel;

/** The instance-wide Grafana push token; its own binding keeps it apart from every other sealed secret. */
final readonly class GrafanaApiKeyCipher
{
    private const string PURPOSE = 'grafana-api-key';

    public function __construct(private InstanceSecretCipher $cipher)
    {
    }

    public function seal(string $plainToken): SealedSecret
    {
        return $this->cipher->seal(SecretBindingModel::forInstance(self::PURPOSE), $plainToken);
    }

    public function open(SealedSecret $sealed): string
    {
        return $this->cipher->open(SecretBindingModel::forInstance(self::PURPOSE), $sealed);
    }
}
