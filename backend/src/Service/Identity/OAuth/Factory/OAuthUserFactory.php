<?php

declare(strict_types=1);

namespace App\Service\OAuth\Factory;

use App\Entity\User;
use App\Service\Auth\RegistrationPolicy;
use App\Service\OAuth\Model\OAuthIdentityModel;
use Psr\Clock\ClockInterface;

final readonly class OAuthUserFactory
{
    public function __construct(private ClockInterface $clock, private RegistrationPolicy $policy)
    {
    }

    /**
     * A first sign-in with no matching account skips pending_verification whatever the confirmation toggle: the
     * provider proved the address. Admin approval still applies.
     */
    public function create(OAuthIdentityModel $identity): User
    {
        $now = $this->clock->now();
        $user = new User($this->loginIdentifierFor($identity), $now);
        if ($identity->isLinkableByEmail()) {
            $user->markEmailVerified($now);
        }

        if ($this->policy->approvalRequired()) {
            $user->queueForApproval();
        } else {
            $user->approve($now);
        }

        return $user;
    }

    /**
     * Only a linkable address becomes the login identifier; anything else gets a placeholder and stays on the
     * UserIdentity row, or an unverified claim could squat a real address. docs/security.md#oauth-account-linking
     */
    private function loginIdentifierFor(OAuthIdentityModel $identity): string
    {
        if ($identity->isLinkableByEmail()) {
            \assert(null !== $identity->email);

            return $identity->email;
        }

        return $this->placeholderEmail($identity);
    }

    /**
     * `<provider>-<hash of sub>@oauth.invalid` for an identity with no usable address (Apple sends it only once):
     * never routable, stable across sign-ins, and the subject stays out of the admin UI.
     */
    private function placeholderEmail(OAuthIdentityModel $identity): string
    {
        return \sprintf(
            '%s-%s@oauth.invalid',
            $identity->provider,
            substr(hash('sha256', $identity->providerUserId), 0, 32),
        );
    }
}
