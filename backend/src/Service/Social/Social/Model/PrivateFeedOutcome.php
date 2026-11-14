<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

enum PrivateFeedOutcome
{
    case Created;
    case AlreadySubscribed;
}
