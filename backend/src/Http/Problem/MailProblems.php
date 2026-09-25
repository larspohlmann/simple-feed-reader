<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use Symfony\Component\HttpFoundation\Response;

final readonly class MailProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof IncompleteMailConfigurationException => new ResolvedProblem(new ApiProblem(
                'incomplete_mail_configuration',
                'Incomplete mail configuration',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            default => null,
        };
    }
}
