<?php

declare(strict_types=1);

namespace App\Service\Discovery\Model;

enum NoPreviewReason: string
{
    case HiddenProfile = 'hidden_profile';
    case ConnectBlueskyAccount = 'connect_bluesky_account';
}
