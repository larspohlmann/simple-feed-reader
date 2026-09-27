<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\ProxyConnection;
use App\Entity\ProxyServerSettings;
use App\Entity\SealedSecret;
use App\Enum\ProxyType;

final class UnreadableProxyPasswordRows
{
    public static function enabledWithUnreadablePassword(): ProxyServerSettings
    {
        return self::row(true);
    }

    public static function disabledWithUnreadablePassword(): ProxyServerSettings
    {
        return self::row(false);
    }

    private static function row(bool $enabled): ProxyServerSettings
    {
        $row = new ProxyServerSettings();
        $row->apply(
            new ProxyConnection($enabled, true, ProxyType::Socks5, 'proxy.example', 1080, 'user'),
            new SealedSecret('not base64!', 'bm9uY2U=', 'c2FsdA==', 1),
        );

        return $row;
    }
}
