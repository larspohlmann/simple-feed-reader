<?php

declare(strict_types=1);

namespace App\Service\Library\Subscription\SubscribeRoute;

use App\Service\Library\Subscription\Model\SubscribeOutcomeModel;
use App\Service\Library\Subscription\Model\SubscribeRequestModel;

/** A source that subscribes some URLs its own way, asked before discovery. */
interface SubscribeRouteInterface
{
    /** @return SubscribeOutcomeModel|null null when the URL is not this route's to take */
    public function subscribe(SubscribeRequestModel $request): ?SubscribeOutcomeModel;
}
