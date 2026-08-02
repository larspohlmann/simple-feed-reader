<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * The client-facing form of Service\Subscription\Exception\ScrapingDisabledException.
 * SubscriptionController rethrows the service's internal refusal as this so
 * ApiExceptionListener renders a problem+json document instead of the opaque
 * 500 a bare RuntimeException would otherwise produce.
 */
final class ScrapingDisabledApiException extends ApiException
{
    public function __construct(string $detail, ?\Throwable $previous = null)
    {
        parent::__construct(
            'scraping_disabled',
            Response::HTTP_FORBIDDEN,
            'Website scraping is disabled',
            $detail,
            [],
            $previous,
        );
    }
}
