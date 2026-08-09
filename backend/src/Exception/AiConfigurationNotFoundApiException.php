<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class AiConfigurationNotFoundApiException extends ApiException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'ai_configuration_not_found',
            Response::HTTP_NOT_FOUND,
            'AI configuration not found',
            'No such AI configuration for this account.',
            [],
            $previous,
        );
    }
}
