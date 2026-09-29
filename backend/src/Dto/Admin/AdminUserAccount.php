<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/** The account fields the admin detail shows, picked one by one in AdminUserJson::account(), never the whole User. */
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
