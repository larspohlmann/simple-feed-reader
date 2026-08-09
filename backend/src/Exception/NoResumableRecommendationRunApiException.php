<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class NoResumableRecommendationRunApiException extends ApiException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'no_resumable_recommendation_run',
            Response::HTTP_CONFLICT,
            'No recommendation run to resume',
            'There is no failed run to resume; start a new one instead.',
            [],
            $previous,
        );
    }
}
