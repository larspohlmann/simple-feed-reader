<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/** Which App\Service module a class belongs to (docs/architecture.md §9). */
final class ServiceModules
{
    public const string SERVICE_NAMESPACE = 'App\\Service\\';

    /** Directories below Service/ that are modules of their own; the rest of their parent stays the parent's (#1344). */
    private const array SUB_MODULES = ['Recommendation\\Llm'];

    private function __construct()
    {
    }

    /** The sub-module the class sits in, else its first segment below App\Service (a loose class's own name). */
    public static function of(string $className): string
    {
        $relativeName = substr($className, \strlen(self::SERVICE_NAMESPACE));

        return array_find(
            self::SUB_MODULES,
            static fn (string $subModule): bool => $relativeName === $subModule
                || str_starts_with($relativeName, $subModule . '\\'),
        ) ?? explode('\\', $relativeName)[0];
    }
}
