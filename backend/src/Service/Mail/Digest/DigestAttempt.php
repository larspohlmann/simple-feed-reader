<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

enum DigestAttempt
{
    case NotDue;
    case Ineligible;
    case NothingToReport;
    case SendFailed;
    case Sent;
}
