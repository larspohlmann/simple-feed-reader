<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Guards against dropping the instance to zero administrators who can
 * actually act: UserRepository::countActiveAdmins() counts only Active
 * admins, so this fires as soon as a delete would leave none — including
 * when the target is the last ACTIVE admin even though a suspended admin
 * still exists on the row, since a suspended admin cannot approve, suspend,
 * reject or reinstate anyone (`^/api/admin/` requires ROLE_ADMIN, which the
 * authentication layer already refuses to a non-Active account). It does
 * NOT protect hasAnyAdmin()'s first-run-setup invariant — that check stays
 * status-blind on purpose. 409, not 422: the request is well-formed, the
 * instance's current state is what forbids it.
 */
final class LastAdminException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'last_admin',
            409,
            'Last administrator',
            'This is the only administrator account. Promote another account first.',
        );
    }
}
