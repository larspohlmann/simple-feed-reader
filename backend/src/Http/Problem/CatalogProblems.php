<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Catalog\Exception\InvalidCatalogDocumentException;
use Symfony\Component\HttpFoundation\Response;

final readonly class CatalogProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof InvalidCatalogDocumentException => new ResolvedProblem(new ApiProblem(
                'request_error',
                Response::$statusTexts[Response::HTTP_UNPROCESSABLE_ENTITY],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            )),
            default => null,
        };
    }
}
