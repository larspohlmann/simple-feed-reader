<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class FeedDiscoveryException extends ApiException
{
    public function __construct(?string $detail = null, ?\Throwable $previous = null)
    {
        parent::__construct(
            'feed_unreachable',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Could not read a feed from that address',
            $detail,
            [],
            $previous,
        );
    }
}
