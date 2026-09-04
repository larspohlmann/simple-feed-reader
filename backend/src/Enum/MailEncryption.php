<?php

declare(strict_types=1);

namespace App\Enum;

enum MailEncryption: string
{
    case None = 'none';
    case Starttls = 'starttls';
    case Tls = 'tls';
}
