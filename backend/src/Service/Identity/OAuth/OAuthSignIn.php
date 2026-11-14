<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AccountStatusException;
use App\Security\LoginUserChecker;
use App\Service\Auth\Exception\AccountNotActiveException;
use App\Service\Auth\Exception\InvalidTokenException;
use App\Service\OAuth\Model\OAuthIdentityModel;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\InvalidArgumentException;
use Random\RandomException;

/**
 * Turns a provider-verified identity into a session in two legs: the callback gets a one-time login code, never a
 * JWT, and the SPA redeems it. What the code is bound to and where the status gate sits live here, not in HTTP.
 */
final readonly class OAuthSignIn
{
    public function __construct(
        private OAuthAccountLinker $linker,
        private LoginCodeStore $loginCodes,
        private UserRepository $users,
        private LoginUserChecker $loginUserChecker,
        private JWTTokenManagerInterface $jwtManager,
    ) {
    }

    /**
     * Deliberately not gated on account status: redeemLoginCode() re-runs the gate, so a pending or suspended user
     * gets a problem+json saying why instead of a generic redirect error.
     *
     * @throws InvalidArgumentException
     * @throws RandomException
     */
    public function issueLoginCode(OAuthIdentityModel $identity, string $browserToken): string
    {
        // resolve() deliberately returns suspended and rejected users unchanged
        // — linking proves an address, it does not overrule an admin.
        $user = $this->linker->resolve($identity);

        return $this->loginCodes->issue($user->requireId(), $browserToken);
    }

    /**
     * Leg two: spend the code and mint the JWT; every refusal, a since-deleted account included, is one answer.
     *
     * @throws InvalidTokenException
     * @throws InvalidArgumentException
     */
    public function redeemLoginCode(string $code, ?string $browserToken): string
    {
        $user = $this->users->find($this->loginCodes->consume($code, $browserToken));

        // The account was deleted, or purged, between the callback and this
        // request. Same answer as a bad code — there is nothing to sign in as,
        // and the two must not be distinguishable.
        if (!$user instanceof User) {
            throw new InvalidTokenException();
        }

        $this->assertMayLogIn($user);

        return $this->jwtManager->create($user);
    }

    /**
     * The only status gate on this path: the linker returns suspended and rejected users unchanged. Translated here,
     * not globally: the api firewall throws the same exception for a live token, whose 401 must disclose nothing.
     */
    private function assertMayLogIn(User $user): void
    {
        try {
            $this->loginUserChecker->checkPostAuth($user);
        } catch (AccountStatusException $exception) {
            throw new AccountNotActiveException($exception->accountStatus);
        }
    }
}
