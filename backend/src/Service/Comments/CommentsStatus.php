<?php

declare(strict_types=1);

namespace App\Service\Comments;

enum CommentsStatus: string
{
    case Ok = 'ok';
    case Throttled = 'throttled';
    case Failed = 'failed';
}
