<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Recommendation\Exception\NoActiveRecommendationRunException;
use App\Service\Recommendation\Exception\NoResumableRecommendationRunException;
use App\Service\Recommendation\Exception\RecommendationRunActiveException;
use Symfony\Component\HttpFoundation\Response;

final readonly class RecommendationRunProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof NoActiveRecommendationRunException => new ResolvedProblem(new ApiProblem(
                'no_active_recommendation_run',
                'No recommendation run is active',
                Response::HTTP_CONFLICT,
                'There is nothing to stop: the run already finished.',
            )),
            $exception instanceof NoResumableRecommendationRunException => new ResolvedProblem(new ApiProblem(
                'no_resumable_recommendation_run',
                'No recommendation run to resume',
                Response::HTTP_CONFLICT,
                'There is no failed run to resume; start a new one instead.',
            )),
            $exception instanceof RecommendationRunActiveException => new ResolvedProblem(new ApiProblem(
                'recommendation_run_active',
                'A recommendation run is still active',
                Response::HTTP_CONFLICT,
                'Wait for the current run to finish, then try again.',
            )),
            default => null,
        };
    }
}
