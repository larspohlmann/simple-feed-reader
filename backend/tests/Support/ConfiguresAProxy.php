<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Proxy\ProxySettings;

trait ConfiguresAProxy
{
    private function configureAProxy(): void
    {
        self::getContainer()->get(ProxySettings::class)->update(
            SettingsRequests::proxy(type: 'SOCKS5', host: 'proxy.example', port: 1080)->toUpdate(),
        );
    }
}
