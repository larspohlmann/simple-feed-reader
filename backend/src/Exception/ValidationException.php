<?php

declare(strict_types=1);

namespace App\Exception;

final class ValidationException extends ApiException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(array $errors)
    {
        parent::__construct(
            'validation_error',
            422,
            'Validation failed',
            'One or more fields are invalid.',
            $errors,
        );
    }
}
