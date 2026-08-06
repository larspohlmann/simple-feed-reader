<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class AiNotConfiguredApiException extends ApiException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'ai_not_configured',
            Response::HTTP_NOT_FOUND,
            'No AI provider is configured',
            'Save an endpoint and an API key first.',
            [],
            $previous,
        );
    }
}
