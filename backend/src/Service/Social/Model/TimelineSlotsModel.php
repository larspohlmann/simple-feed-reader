<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\SocialAccount;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\RequestSlotModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Support\HostKey;

/**
 * A timeline read holds its account's slot and its server's. The server slot is not an RSS host slot: a feed and a
 * timeline on one host never count against each other.
 */
final readonly class TimelineSlotsModel
{
    public function __construct(private int $perAccount, private int $perHost)
    {
    }

    public function ticketFor(SocialAccount $account, ApiRequestModel $request): RequestTicketModel
    {
        return RequestTicketModel::api($request, [
            RequestSlotModel::named(sprintf('social-account:%d', $account->requireId()), $this->perAccount),
            RequestSlotModel::named('social-host:' . HostKey::forUrl($request->url), $this->perHost),
        ]);
    }
}
