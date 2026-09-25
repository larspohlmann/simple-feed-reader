<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Backup\Exception\BackupDoesNotFitException;
use App\Service\Backup\Exception\BackupLoadFailedException;
use App\Service\Backup\Exception\InvalidBackupException;
use Symfony\Component\HttpFoundation\Response;

final readonly class BackupProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof BackupDoesNotFitException => new ResolvedProblem(new ApiProblem(
                'backup_does_not_fit',
                'The backup does not fit this account',
                Response::HTTP_CONFLICT,
                $exception->getMessage(),
            )),
            $exception instanceof InvalidBackupException => new ResolvedProblem(new ApiProblem(
                'invalid_backup',
                'Invalid backup file',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            $exception instanceof BackupLoadFailedException => new ResolvedProblem(new ApiProblem(
                'backup_load_failed',
                'The backup could not be loaded',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            default => null,
        };
    }
}
