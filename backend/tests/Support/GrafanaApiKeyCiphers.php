<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;

final class GrafanaApiKeyCiphers
{
    public static function withTestSecret(): GrafanaApiKeyCipher
    {
        return self::under(TestInstanceSecret::VALUE);
    }

    public static function under(string $secret): GrafanaApiKeyCipher
    {
        return new GrafanaApiKeyCipher(new InstanceSecretCipher($secret));
    }
}
