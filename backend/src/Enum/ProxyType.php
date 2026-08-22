<?php

declare(strict_types=1);

namespace App\Enum;

enum ProxyType: string
{
    case Socks5 = 'SOCKS5';
    case Http = 'HTTP';
}
