<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Exception;

use App\Enum\SocialNetwork;

final class NetworkWithoutFeedsException extends \LogicException
{
    public static function for(SocialNetwork $network): self
    {
        return new self(sprintf('%s carries no feeds.', $network->displayName()));
    }
}
