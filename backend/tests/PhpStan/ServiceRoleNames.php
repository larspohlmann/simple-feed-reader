<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/** Where a class sits, read off its name alone: module, role folder and the area around it. */
final class ServiceRoleNames
{
    public const string FACTORY = 'Factory';
    public const string MODEL = 'Model';
    public const string DTO = 'Dto';
    public const string PASS = 'Pass';
    public const string SUPPORT = 'Support';
    public const string EXCEPTION = 'Exception';
    public const string MESSAGE = 'Message';
    public const string HANDLER = 'Handler';

    private const array ROLES = [
        self::FACTORY, self::MODEL, self::DTO, self::PASS, self::SUPPORT, self::EXCEPTION, self::MESSAGE, self::HANDLER,
    ];

    private function __construct()
    {
    }

    public static function isServiceOrHttp(string $name): bool
    {
        return ClassNameReferences::isInAnyOf($name, ['App\\Service\\', 'App\\Http\\']);
    }

    public static function isService(string $name): bool
    {
        return ClassNameReferences::isInAnyOf($name, ['App\\Service\\']);
    }

    public static function isListener(string $name): bool
    {
        return ClassNameReferences::isInAnyOf($name, ['App\\EventListener\\']);
    }

    public static function isProductionNamespace(string $namespace): bool
    {
        return ClassNameReferences::isInAnyOf($namespace, ['App\\'])
            && !ClassNameReferences::isInAnyOf($namespace, ['App\\Tests\\']);
    }

    public static function namespaceOf(string $name): string
    {
        $separator = strrpos($name, '\\');

        return false === $separator ? '' : substr($name, 0, $separator);
    }

    public static function shortNameOf(string $name): string
    {
        $separator = strrpos($name, '\\');

        return false === $separator ? $name : substr($name, $separator + 1);
    }

    /** `App\Service\<Module>` for a service, `App\<Layer>` for anything else (#1161 D1). */
    public static function moduleOf(string $name): string
    {
        $segments = explode('\\', $name);
        $depth = self::isService($name) ? 3 : 2;

        return implode('\\', \array_slice($segments, 0, $depth));
    }

    public static function roleOfClass(string $name): ?string
    {
        return self::roleOf(self::namespaceOf($name));
    }

    /** The innermost role folder a namespace sits in, or null in an area root or an interface folder. */
    private static function roleOf(string $namespace): ?string
    {
        foreach (array_reverse(explode('\\', $namespace)) as $segment) {
            if (\in_array($segment, self::ROLES, true)) {
                return $segment;
            }
        }

        return null;
    }

    /** The namespace a role folder belongs to: everything before its first role segment. */
    public static function areaOf(string $namespace): string
    {
        $area = [];
        foreach (explode('\\', $namespace) as $segment) {
            if (\in_array($segment, self::ROLES, true)) {
                break;
            }
            $area[] = $segment;
        }

        return implode('\\', $area);
    }

    /** The namespace up to and including the innermost segment named $role. */
    public static function roleRootOf(string $namespace, string $role): string
    {
        $segments = explode('\\', $namespace);
        $position = array_search($role, array_reverse($segments, true), true);

        return false === $position ? $namespace : implode('\\', \array_slice($segments, 0, $position + 1));
    }

    public static function withoutSuffix(string $name, string $suffix): string
    {
        return str_ends_with($name, $suffix) ? substr($name, 0, -\strlen($suffix)) : $name;
    }
}
