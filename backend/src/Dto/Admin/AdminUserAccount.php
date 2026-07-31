<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/**
 * The account identity fields the admin detail screen shows. Hand-built by
 * the controller field-by-field — never hydrated from the User entity as a
 * whole — so a column added to User later cannot reach an admin's browser
 * merely because it exists. Note what is absent: the password hash and every
 * token column.
 */
final readonly class AdminUserAccount
{
    /**
     * @param list<string> $roles
     * @param list<string> $identities the sign-in providers this account used
     */
    public function __construct(
        public int $id,
        public string $email,
        public string $status,
        public array $roles,
        public string $locale,
        public string $createdAt,
        public ?string $approvedAt,
        public ?string $lastLoginAt,
        public array $identities,
    ) {
    }
}
