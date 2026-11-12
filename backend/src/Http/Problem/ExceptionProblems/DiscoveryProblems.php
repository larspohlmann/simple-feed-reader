<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Discovery\Exception\ScrapingDisabledException;
use App\Service\Discovery\Exception\SiteThrottledException;
use Symfony\Component\HttpFoundation\Response;

final readonly class DiscoveryProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof ScrapingDisabledException => new ResolvedProblem(new ApiProblem(
                'scraping_disabled',
                'Website scraping is disabled',
                Response::HTTP_FORBIDDEN,
                $exception->getMessage(),
            )),
            $exception instanceof SiteThrottledException => new ResolvedProblem(new ApiProblem(
                'feed_throttled',
                'Site is rationing requests',
                Response::HTTP_SERVICE_UNAVAILABLE,
                'The site asked us to slow down. Try again in a minute.',
            )),
            default => null,
        };
    }
}
