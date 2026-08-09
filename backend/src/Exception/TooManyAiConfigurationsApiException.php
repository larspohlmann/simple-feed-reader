<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class TooManyAiConfigurationsApiException extends ApiException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'ai_configuration_limit',
            Response::HTTP_CONFLICT,
            'Too many AI configurations',
            'This account already holds the maximum number of AI configurations.',
            [],
            $previous,
        );
    }
}
