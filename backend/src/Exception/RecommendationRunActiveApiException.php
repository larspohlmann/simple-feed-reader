<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class RecommendationRunActiveApiException extends ApiException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'recommendation_run_active',
            Response::HTTP_CONFLICT,
            'A recommendation run is still active',
            'Wait for the current run to finish, then try again.',
            [],
            $previous,
        );
    }
}
