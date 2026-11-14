<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\SocialAccount;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\RequestTicketModel;

/** A timeline read holds its account's slot and its server's. */
final readonly class TimelineSlotsModel
{
    public function __construct(private int $perAccount, private int $perHost)
    {
    }

    public function ticketFor(SocialAccount $account, ApiRequestModel $request): RequestTicketModel
    {
        return RequestTicketModel::api($request, [
            new SocialAccountSlotModel($account->requireId(), $this->perAccount),
            new SocialServerSlotModel($this->perHost),
        ]);
    }
}
