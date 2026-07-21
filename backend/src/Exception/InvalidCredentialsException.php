<?php

declare(strict_types=1);

namespace App\Exception;

final class InvalidCredentialsException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'invalid_credentials',
            401,
            'Invalid credentials',
            'Email address or password is incorrect.',
        );
    }
}
