<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Guards against dropping the instance to zero administrators who can act:
 * UserRepository::countActiveAdmins() counts only Active admins, so this fires
 * as soon as a delete would leave none — even when a suspended admin still
 * exists on the row, since a suspended admin cannot approve, suspend, reject
 * or reinstate anyone (`^/api/admin/` requires ROLE_ADMIN, refused to a
 * non-Active account). Does NOT protect hasAnyAdmin()'s first-run-setup
 * invariant, which stays status-blind on purpose. 409, not 422: the request is
 * well-formed, the instance's current state is what forbids it.
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
