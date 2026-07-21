<?php

declare(strict_types=1);

namespace App\Enum;

enum TokenPurpose: string
{
    case VerifyEmail = 'verify_email';
    case ResetPassword = 'reset_password';
}
