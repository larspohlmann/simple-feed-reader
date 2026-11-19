<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Social\Bluesky\Exception\BlueskyProfileHiddenException;
use Symfony\Component\HttpFoundation\Response;

final readonly class BlueskyProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof BlueskyProfileHiddenException => new ResolvedProblem(new ApiProblem(
                'bluesky_profile_hidden',
                'Profile hidden from logged-out readers',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            default => null,
        };
    }
}
