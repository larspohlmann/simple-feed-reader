<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Model;

enum RunProfileState
{
    case Ready;
    case Building;
    case Failed;
}
