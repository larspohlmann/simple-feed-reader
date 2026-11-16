<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Curation\Trending\Exception\TrendingStoryNotFollowedException;
use App\Service\Curation\Trending\Exception\TrendingStoryNotFoundException;
use Symfony\Component\HttpFoundation\Response;

final readonly class TrendingProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof TrendingStoryNotFoundException => new ResolvedProblem(new ApiProblem(
                'trending_story_not_found',
                'Trending story not found',
                Response::HTTP_NOT_FOUND,
                'No Trending story has that id.',
            )),
            $exception instanceof TrendingStoryNotFollowedException => new ResolvedProblem(new ApiProblem(
                'trending_story_not_followed',
                'Trending story not followed',
                Response::HTTP_NOT_FOUND,
                'You do not follow a Trending story with that id.',
            )),
            default => null,
        };
    }
}
