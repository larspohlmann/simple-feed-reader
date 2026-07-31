<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/**
 * The full payload of GET /api/admin/users/{id}: one account's identity,
 * footprint, and its complete tag and subscription lists. Passed straight to
 * JsonResponse — json_encode walks the public readonly properties of this and
 * every nested DTO, so the wire shape is exactly what PHPStan already checked.
 */
final readonly class AdminUserDetail
{
    /**
     * @param list<AdminUserTag> $tags
     * @param list<AdminUserSubscription> $subscriptions
     */
    public function __construct(
        public AdminUserAccount $user,
        public AdminUserFootprint $footprint,
        public array $tags,
        public array $subscriptions,
    ) {
    }
}
