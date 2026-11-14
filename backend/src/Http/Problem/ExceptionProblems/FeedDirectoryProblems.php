<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Sourcing\FeedDirectory\Exception\InvalidFeedDirectoryScopeException;
use App\Service\Sourcing\FeedDirectory\Exception\UnknownFeedDirectoryCountryException;
use Symfony\Component\HttpFoundation\Response;

final readonly class FeedDirectoryProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof UnknownFeedDirectoryCountryException => new ResolvedProblem(new ApiProblem(
                'feed_directory_country_not_found',
                'Country not in the feed directory',
                Response::HTTP_NOT_FOUND,
                $exception->getMessage(),
            )),
            $exception instanceof InvalidFeedDirectoryScopeException => new ResolvedProblem(new ApiProblem(
                'feed_directory_scope_invalid',
                'Invalid feed directory scope',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            default => null,
        };
    }
}
