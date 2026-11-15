<?php

declare(strict_types=1);

namespace App\Enum;

enum LanguageSource: string
{
    case Stated = 'stated';
    case Guessed = 'guessed';
}
