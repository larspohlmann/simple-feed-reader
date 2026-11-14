<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Repository\ReaderAuditRepository;
use App\Service\ReaderAudit\Exception\NoAuditUserException;

/** No account given resolves to the one with the most subscriptions: installations keep test accounts beside it. */
final readonly class AuditUserResolver
{
    public function __construct(private ReaderAuditRepository $audit)
    {
    }

    public function resolve(?string $idOrEmail): int
    {
        return $idOrEmail === null ? $this->widestSubscriber() : $this->named($idOrEmail);
    }

    private function named(string $idOrEmail): int
    {
        return $this->audit->userIdNamed($idOrEmail)
            ?? throw new NoAuditUserException(\sprintf('No user matches "%s".', $idOrEmail));
    }

    private function widestSubscriber(): int
    {
        return $this->audit->widestSubscriberId()
            ?? throw new NoAuditUserException('No account holds a subscription to audit.');
    }
}
