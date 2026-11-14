<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Library\Subscription\Model\SubscribeOutcomeModel;
use App\Service\Library\Subscription\Model\SubscribeRequestModel;
use App\Service\Library\Subscription\SubscribeRoute\SubscribeRouteInterface;

final class RecordingSubscribeRoute implements SubscribeRouteInterface
{
    /** @var list<SubscribeRequestModel> */
    public array $requests = [];

    public function __construct(private readonly ?SubscribeOutcomeModel $outcome)
    {
    }

    public function subscribe(SubscribeRequestModel $request): ?SubscribeOutcomeModel
    {
        $this->requests[] = $request;

        return $this->outcome;
    }
}
