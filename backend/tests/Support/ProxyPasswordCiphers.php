<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Foundation\Crypto\InstanceSecretCipher;
use App\Service\Outbound\Proxy\Crypto\ProxyPasswordCipher;

final class ProxyPasswordCiphers
{
    public static function withTestSecret(): ProxyPasswordCipher
    {
        return self::under(TestInstanceSecret::VALUE);
    }

    public static function under(string $secret): ProxyPasswordCipher
    {
        return new ProxyPasswordCipher(new InstanceSecretCipher($secret));
    }
}
