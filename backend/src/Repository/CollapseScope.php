<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\QueryBuilder;

final readonly class CollapseScope
{
    /**
     * @param \Closure(QueryBuilder, EntryAliases): void $copies
     * @param \Closure(QueryBuilder, EntryAliases): void $crossPosts
     */
    private function __construct(public \Closure $copies, public \Closure $crossPosts)
    {
    }

    /**
     * @param callable(QueryBuilder, EntryAliases): void $applyScope
     */
    public static function fromScope(callable $applyScope): self
    {
        $scope = $applyScope(...);

        return new self($scope, $scope);
    }

    /**
     * @param callable(QueryBuilder, EntryAliases): void $applyCopyScope
     * @param callable(QueryBuilder, EntryAliases): void $applyCrossPostScope
     */
    public static function fromScopes(callable $applyCopyScope, callable $applyCrossPostScope): self
    {
        return new self($applyCopyScope(...), $applyCrossPostScope(...));
    }
}
