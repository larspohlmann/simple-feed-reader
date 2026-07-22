<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class AlreadySubscribedException extends ApiException
{
    public function __construct(?string $detail = null)
    {
        parent::__construct('already_subscribed', Response::HTTP_CONFLICT, 'Already subscribed to that feed', $detail);
    }
}
