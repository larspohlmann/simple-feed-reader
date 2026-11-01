<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Social\Model\PublishedPostModel;

final class PublishedPostJson
{
    /** @return array{address: string, url: string|null} */
    public static function of(PublishedPostModel $reply): array
    {
        return ['address' => $reply->address, 'url' => $reply->url];
    }
}
