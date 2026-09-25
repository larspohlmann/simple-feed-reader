<?php

declare(strict_types=1);

namespace App\Http\Problem;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One module's exceptions mapped to problem documents; null means the exception belongs to another module. */
#[AutoconfigureTag('app.exception_problems')]
interface ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem;
}
