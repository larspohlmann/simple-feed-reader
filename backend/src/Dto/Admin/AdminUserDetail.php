<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/**
 * The payload of GET /api/admin/users/{id}. JsonResponse encodes its public properties and those of every nested
 * DTO, so these classes are the wire shape.
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
        public AdminUserLimits $limits,
    ) {
    }
}
