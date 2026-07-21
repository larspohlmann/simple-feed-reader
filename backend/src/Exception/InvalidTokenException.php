<?php

declare(strict_types=1);

namespace App\Exception;

final class InvalidTokenException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'invalid_token',
            400,
            'Invalid token',
            'This link is invalid, already used, or expired.',
        );
    }
}
