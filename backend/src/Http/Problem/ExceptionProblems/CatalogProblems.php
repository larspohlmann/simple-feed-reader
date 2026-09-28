<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Catalog\Exception\InvalidCatalogDocumentException;
use Symfony\Component\HttpFoundation\Response;

final readonly class CatalogProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof InvalidCatalogDocumentException => new ResolvedProblem(
                ApiProblem::forStatus(Response::HTTP_UNPROCESSABLE_ENTITY),
            ),
            default => null,
        };
    }
}
