<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

enum DigestFormat: string
{
    case Html = 'html';
    case Text = 'text';
}
