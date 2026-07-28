<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How an account reached the approval queue. Passed on UserAwaitingApproval so
 * the admin notification can say how someone signed up without re-deriving it
 * from the user row (a null password hash is not a reliable "is OAuth" signal).
 */
enum RegistrationMethod
{
    case EmailPassword;
    case OAuth;
}
