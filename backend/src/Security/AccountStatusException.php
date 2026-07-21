<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\AccountStatusException as SymfonyAccountStatusException;

/**
 * Carries the lifecycle status through the security layer so the failure
 * handler can report *why* login was refused without re-loading the user.
 */
final class AccountStatusException extends SymfonyAccountStatusException
{
    public function __construct(public readonly string $accountStatus)
    {
        parent::__construct('Account is not active.');
    }

    public function getMessageKey(): string
    {
        return 'Account is not active.';
    }

    /**
     * The parent only serializes its own $user, so $accountStatus has to be
     * carried explicitly - otherwise it stays uninitialised after a round trip
     * and reading it throws.
     *
     * @return array{string, array<mixed>}
     */
    public function __serialize(): array
    {
        return [$this->accountStatus, parent::__serialize()];
    }

    /** @param array{string, array<mixed>} $data */
    public function __unserialize(array $data): void
    {
        [$this->accountStatus, $parentData] = $data;
        parent::__unserialize($parentData);
    }
}
