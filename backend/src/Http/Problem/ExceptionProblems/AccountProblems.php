<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Account\Exception\LastAdminException;
use Symfony\Component\HttpFoundation\Response;

final readonly class AccountProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof LastAdminException => new ResolvedProblem(new ApiProblem(
                'last_admin',
                'Last administrator',
                Response::HTTP_CONFLICT,
                'This is the only administrator account. Promote another account first.',
            )),
            default => null,
        };
    }
}
