<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

enum MailTestFailure: string
{
    case NotConfigured = 'not_configured';
    case NoFromAddress = 'no_from_address';
    case SecretUnreadable = 'secret_unreadable';
    case SendRejected = 'send_rejected';
}
