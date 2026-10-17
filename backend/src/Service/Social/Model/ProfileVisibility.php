<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

enum ProfileVisibility
{
    case Unknown;
    case Public;
    case Hidden;
}
