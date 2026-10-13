<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model;

use App\Enum\SocialNetwork;

final readonly class RefreshFeedSetModel
{
    public function __construct(public SocialNetwork $network)
    {
    }

    public function key(): string
    {
        return 'network-' . $this->network->value;
    }
}
