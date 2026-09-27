<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;

final class ProxyPasswordCiphers
{
    public const string SECRET = 'test-master-secret-at-least-32-chars-long!!';

    public static function withTestSecret(): ProxyPasswordCipher
    {
        return self::under(self::SECRET);
    }

    public static function under(string $secret): ProxyPasswordCipher
    {
        return new ProxyPasswordCipher(new InstanceSecretCipher($secret));
    }
}
