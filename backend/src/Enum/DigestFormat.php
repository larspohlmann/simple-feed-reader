<?php

declare(strict_types=1);

namespace App\Enum;

enum DigestFormat: string
{
    case Html = 'html';
    case Text = 'text';
}
