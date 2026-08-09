<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class NoActiveRecommendationRunApiException extends ApiException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'no_active_recommendation_run',
            Response::HTTP_CONFLICT,
            'No recommendation run is active',
            'There is nothing to stop: the run already finished.',
            [],
            $previous,
        );
    }
}
