<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/**
 * One row of the account's own tag list, in the order its owner arranged it.
 */
final readonly class AdminUserTag
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $color,
        public ?string $icon,
        public int $position,
        /** How many of this account's subscriptions carry the tag. */
        public int $feedsCount,
    ) {
    }
}
