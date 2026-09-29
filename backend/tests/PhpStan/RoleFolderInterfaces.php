<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/**
 * Inside Factory/ or Model/, an interface and its implementations sit flat only when every class there implements
 * that one interface; otherwise each interface gets a subfolder named without its FactoryInterface or ModelInterface
 * suffix, and the classes that implement none sit flat (#1202).
 */
final readonly class RoleFolderInterfaces implements ServiceRoleChecker
{
    private const array ROLES = [
        ServiceRoleNames::FACTORY => ServiceRoleCheck::FactoryFolder,
        ServiceRoleNames::MODEL => ServiceRoleCheck::ModelFolder,
    ];

    public function violationsIn(ServiceRoleMap $map): array
    {
        $violations = [];
        foreach (self::ROLES as $role => $check) {
            foreach (self::rootsOf($map, $role) as $root) {
                $tree = new RoleFolderTree($root, $role, $map->classesUnder($root));
                $violations = [...$violations, ...self::misplacedIn($tree, $check)];
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private static function rootsOf(ServiceRoleMap $map, string $role): array
    {
        $roots = [];
        foreach ($map->classes() as $class) {
            if ($role === $class->role() && ServiceRoleNames::isServiceOrHttp($class->name())) {
                $roots[ServiceRoleNames::roleRootOf($class->namespace(), $role)] = true;
            }
        }

        return array_keys($roots);
    }

    /** @return list<ServiceRoleViolation> */
    private static function misplacedIn(RoleFolderTree $tree, ServiceRoleCheck $check): array
    {
        $violations = [];
        foreach ($tree->members as $member) {
            $folders = $tree->foldersFor($member);
            if (!\in_array($member->namespace(), $folders, true)) {
                $violations[] = new ServiceRoleViolation(
                    $check,
                    $member,
                    sprintf('sits in the wrong %s/ folder', $tree->role),
                    $member->movedTo($folders[0]),
                );
            }
        }

        return $violations;
    }
}
