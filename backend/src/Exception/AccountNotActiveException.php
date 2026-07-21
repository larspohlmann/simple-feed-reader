<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The credentials were correct but the account may not log in yet. The client
 * shows a different message per status, so the status travels in the payload.
 */
final class AccountNotActiveException extends ApiException
{
    public function __construct(public readonly string $accountStatus)
    {
        parent::__construct(
            'account_not_active',
            403,
            'Account not active',
            match ($accountStatus) {
                'pending_verification' => 'Confirm your email address first.',
                'pending_approval' => 'An administrator has not approved this account yet.',
                'suspended' => 'This account has been suspended.',
                'rejected' => 'This account was rejected.',
                default => 'This account cannot sign in.',
            },
        );
    }
}
