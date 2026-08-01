<?php

declare(strict_types=1);

namespace App\Exception;

final class InvalidSetupSecretException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'invalid_setup_secret',
            403,
            'Forbidden',
            'The setup secret is incorrect.',
        );
    }
}
