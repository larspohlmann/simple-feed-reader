<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Deleting the last administrator would leave the instance with nobody able to
 * approve an account — and would re-open first-run setup, the invariant
 * UserRepository::hasAnyAdmin() exists to protect. 409, not 422: the request is
 * well-formed, the instance's state is what forbids it.
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
