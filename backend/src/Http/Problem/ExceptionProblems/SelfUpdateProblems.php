<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\SelfUpdate\Exception\NoUpdateAvailableException;
use App\Service\SelfUpdate\Exception\UpdateInProgressException;
use App\Service\SelfUpdate\Exception\UpdaterUnavailableException;
use Symfony\Component\HttpFoundation\Response;

final readonly class SelfUpdateProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof UpdaterUnavailableException => new ResolvedProblem(new ApiProblem(
                'updater_unavailable',
                'The updater is not running',
                Response::HTTP_SERVICE_UNAVAILABLE,
                $exception->getMessage(),
            )),
            $exception instanceof UpdateInProgressException => new ResolvedProblem(new ApiProblem(
                'update_in_progress',
                'An update is already under way',
                Response::HTTP_CONFLICT,
                $exception->getMessage(),
            )),
            $exception instanceof NoUpdateAvailableException => new ResolvedProblem(new ApiProblem(
                'no_update_available',
                'No update available',
                Response::HTTP_CONFLICT,
                $exception->getMessage(),
            )),
            default => null,
        };
    }
}
