<?php

declare(strict_types=1);

namespace App\Entity;

/** Which automated (or manually triggered) mail failed to send (#882). */
enum MailKind: string
{
    case Digest = 'digest';
    case Account = 'account';
    case Test = 'test';
}
