<?php

declare(strict_types=1);

namespace App\Service\Auth\Exception;

/** The credentials were correct but the account may not sign in yet; the client words its message by status. */
final class AccountNotActiveException extends \RuntimeException
{
    public function __construct(public readonly string $accountStatus)
    {
        parent::__construct(match ($accountStatus) {
            'pending_verification' => 'Confirm your email address first.',
            'pending_approval' => 'An administrator has not approved this account yet.',
            'suspended' => 'This account has been suspended.',
            'rejected' => 'This account was rejected.',
            default => 'This account cannot sign in.',
        });
    }
}
