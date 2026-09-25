<?php

declare(strict_types=1);

namespace App\Service\Proxy;

enum ProxyTestFailure: string
{
    case NotConfigured = 'not_configured';
    case SecretUnreadable = 'secret_unreadable';
    case Unreachable = 'unreachable';
    case UnexpectedStatus = 'unexpected_status';
}
