<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\FeedDirectory\Model\SubscribedFeedsModel;

final class SubscribedFeedsJson
{
    /** @return array{urls: list<string>, hosts: list<string>} */
    public static function of(SubscribedFeedsModel $subscribed): array
    {
        return ['urls' => $subscribed->urls, 'hosts' => $subscribed->hosts];
    }
}
