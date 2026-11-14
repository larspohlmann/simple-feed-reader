<?php

declare(strict_types=1);

namespace App\Service\Comments\Model;

enum CommentsStatus: string
{
    case Ok = 'ok';
    case Throttled = 'throttled';
    case Failed = 'failed';
}
