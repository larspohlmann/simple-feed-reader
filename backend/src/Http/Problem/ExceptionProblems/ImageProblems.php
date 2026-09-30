<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Image\Exception\ImageUnavailableException;
use App\Service\Image\Exception\InvalidImageUrlException;
use Symfony\Component\HttpFoundation\Response;

/** A publisher refusing an image is an expected outcome, so never a 5xx the SPA would report as breakage. */
final readonly class ImageProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof InvalidImageUrlException => new ResolvedProblem(
                ApiProblem::forStatus(Response::HTTP_BAD_REQUEST),
            ),
            $exception instanceof ImageUnavailableException => new ResolvedProblem(
                ApiProblem::forStatus(Response::HTTP_NOT_FOUND),
            ),
            default => null,
        };
    }
}
