<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ResolvedProblem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One module's exceptions mapped to problem documents; null means the exception belongs to another module. */
#[AutoconfigureTag('app.exception_problems')]
interface ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem;
}
