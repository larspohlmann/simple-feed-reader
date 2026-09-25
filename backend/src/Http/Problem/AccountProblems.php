<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Account\Exception\LastAdminException;
use Symfony\Component\HttpFoundation\Response;

final readonly class AccountProblems implements ExceptionProblems
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
