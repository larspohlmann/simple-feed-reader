<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Social\Model\PublishedReplyModel;

final class PublishedReplyJson
{
    /** @return array{address: string, url: string|null} */
    public static function of(PublishedReplyModel $reply): array
    {
        return ['address' => $reply->address, 'url' => $reply->url];
    }
}
