<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Dto\OAuth\OAuthIdentity;
use App\Entity\User;
use App\Exception\AccountNotActiveException;
use App\Exception\InvalidTokenException;
use App\Repository\UserRepository;
use App\Security\AccountStatusException;
use App\Security\LoginUserChecker;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\InvalidArgumentException;
use Random\RandomException;

/**
 * Turning a provider-verified identity into a session, in the two legs the
 * sign-in is split across.
 *
 * The split is the design, not an accident of routing: the callback NEVER hands
 * the SPA a JWT. It issues a one-time 30-second login code, and the SPA POSTs it
 * back to be redeemed. A JWT in a redirect's query string would be written to
 * browser history, sent onward in `Referer` headers and logged verbatim by every
 * proxy in between; the code is worthless 30 seconds later and after one use.
 *
 * Both legs live here rather than in OAuthController because they are one rule
 * set, not two endpoints: what the code is bound to, which failures are
 * distinguishable, and where the account-status gate sits. The controller keeps
 * the HTTP of it — reading the cookie, building the redirect, setting and
 * clearing the binding.
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
     * Leg one: the provider vouched for this identity, so mint the code the SPA
     * will redeem.
     *
     * Deliberately NOT gated on account status. A pending_approval or suspended
     * user still receives a login code and redeems it — redemption is where the
     * status check lives, so the SPA gets a proper problem+json explaining WHY it
     * cannot sign in, as the password login does. Refusing here would collapse
     * "you are waiting for approval" into a generic redirect error.
     *
     * The code is worth nothing on its own: it names a user id, and
     * redeemLoginCode() re-reads that user and re-runs the status gate before any
     * token is minted.
     *
     * @param string $browserToken the flow binding this code is tied to, so it
     *                             can only be redeemed by the browser that
     *                             started the flow
     * @throws InvalidArgumentException
     * @throws RandomException
     */
    public function issueLoginCode(OAuthIdentity $identity, string $browserToken): string
    {
        // resolve() deliberately returns suspended and rejected users unchanged
        // — linking proves an address, it does not overrule an admin.
        $user = $this->linker->resolve($identity);

        $userId = $user->getId();
        \assert(null !== $userId);

        return $this->loginCodes->issue($userId, $browserToken);
    }

    /**
     * Leg two: spend the code and mint the JWT.
     * The first two failures below are ONE answer on purpose. An unknown code, an
     * already-spent one, an expired one, one presented by a browser that did not
     * complete the flow, and one naming an account deleted since the callback are
     * all InvalidTokenException — telling them apart could confirm a captured code
     * was still live, or probe which accounts exist.
     *
     * @param string|null $browserToken null when the browser sent no binding
     *                                  cookie, which the store treats as a
     *                                  failure rather than as a reason to skip
     *                                  the check
     *
     * @return string the JWT for the signed-in user
     * @throws InvalidArgumentException
     */
    public function redeemLoginCode(string $code, ?string $browserToken): string
    {
        $userId = $this->loginCodes->consume($code, $browserToken);

        if (null === $userId) {
            throw new InvalidTokenException();
        }

        $user = $this->users->find($userId);

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
     * The status gate, and the ONLY thing between a suspended account and a
     * working JWT on this path.
     *
     * OAuthAccountLinker::resolve() deliberately returns suspended and rejected
     * users unchanged, so nothing earlier refuses them. Delete this call and a
     * suspended user signs in through OAuth.
     *
     * LoginUserChecker::checkPostAuth() is called rather than the rule restated,
     * so a future status change happens in one place and both login paths follow
     * it. The translation below matches LoginFailureHandler's for the password
     * login: the security layer's AccountStatusException is an
     * AuthenticationException, which the API exception listener renders as a bare
     * 401 "unauthorized" — correct for a stolen JWT, wrong here, where the user has
     * just proved an identity and is owed the reason.
     *
     * The plan suggested mapping AccountStatusException globally in the listener
     * instead. Rejected on purpose: the `api` firewall's UserChecker throws the
     * SAME exception for a suspended holder of a live token, and
     * JwtAccessTest::testSuspendedTokenDoesNotLeakAccountStatus pins that path to a
     * 401 disclosing nothing. A global mapping would put a status-disclosing 403
     * one listener-priority change from that path. Disclosure is decided where an
     * identity was verified — here, exactly where LoginFailureHandler decides it.
     */
    private function assertMayLogIn(User $user): void
    {
        try {
            $this->loginUserChecker->checkPostAuth($user);
        } catch (AccountStatusException $e) {
            throw new AccountNotActiveException($e->accountStatus);
        }
    }
}
