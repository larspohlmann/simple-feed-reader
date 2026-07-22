<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Dto\OAuth\OAuthIdentity;
use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\UserStatus;
use App\Repository\UserIdentityRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Turns a provider-verified identity into the local user it belongs to,
 * creating or linking as required.
 *
 * The only class in the OAuth stack that writes to the database, which is what
 * keeps every rule below testable without a network.
 *
 * The rules, in the order they are applied:
 *
 *  1. A UserIdentity row already matches (provider, sub): that is the account,
 *     full stop. Nothing about the address can change the answer.
 *  2. The identity's address is linkable — provider-verified and not a private
 *     relay — and an account holds it: link to that account.
 *  3. Otherwise: a brand new account, in pending_approval, with no password.
 *
 * The ordering matters. Rule 1 before rule 2 means a returning user whose
 * provider address has since changed still lands on their own account instead
 * of being linked onto whoever holds the new address today.
 */
final readonly class OAuthAccountLinker
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private UserIdentityRepository $identities,
        private ClockInterface $clock,
    ) {
    }

    public function resolve(OAuthIdentity $identity): User
    {
        $existing = $this->identities->findOneByProviderAndSubject(
            $identity->provider,
            $identity->providerUserId,
        );

        if (null !== $existing) {
            return $this->refresh($existing, $identity);
        }

        $user = $this->findLinkTarget($identity) ?? $this->createUser($identity);

        $this->attach($user, $identity);
        $this->em->flush();

        return $user;
    }

    /**
     * A returning user. The identity's stored address is kept current so the
     * admin list shows what the provider reports today.
     *
     * Note what is NOT updated: User::$email. That address is the login
     * identifier and the destination for password-reset mail, and rewriting it
     * from a provider callback would mean anyone who compromised a linked
     * provider account could redirect this account's recovery mail to
     * themselves. Changing a login address is a deliberate, separately
     * authenticated action — not a side effect of signing in.
     */
    private function refresh(UserIdentity $existing, OAuthIdentity $identity): User
    {
        if ($identity->email !== $existing->getEmail()) {
            $existing->setEmail($identity->email);
            $this->em->flush();
        }

        return $existing->getUser();
    }

    /**
     * The linking rule. Returns the local account this identity may claim, or
     * null if it may claim none.
     */
    private function findLinkTarget(OAuthIdentity $identity): ?User
    {
        if (!$identity->isLinkableByEmail()) {
            return null;
        }

        \assert(null !== $identity->email);
        $user = $this->users->findOneByEmail($identity->email);

        if (null === $user) {
            return null;
        }

        if (UserStatus::PendingVerification === $user->getStatus()) {
            $this->claimUnverifiedAccount($user);
        }

        // Every other status is returned untouched. OAuth proves an address; it
        // does not overrule an admin, so linking never revives a rejected
        // account, never unsuspends a suspended one, and never re-stamps an
        // active account's password — that last one would revoke the live
        // sessions of a user who did nothing but sign in a second way.
        return $user;
    }

    /**
     * An account that was registered with this address but never confirmed it.
     *
     * Whoever set that password never proved they can read mail at this
     * address — and the provider has just told us somebody else can. So the
     * address changes hands: the account is promoted out of the verification
     * queue, and the unproven password is discarded.
     *
     * HOW THE OWNER GETS BACK IN, stated accurately because an earlier version
     * of this comment got it wrong and a false security argument is how the
     * next person reasons wrongly. It said the rightful owner "reaches a
     * password through the normal reset flow". They cannot, not immediately:
     * this method leaves the account in `pending_approval`, and
     * RegistrationService::requestPasswordReset() returns silently for anything
     * that is not `Active` or `Suspended`. A reset becomes possible only AFTER
     * an admin approves the account.
     *
     * The way back in is the identity that just claimed the row — the person
     * who proved the address at the provider signs in with that provider, which
     * is precisely the request being served here. The password reset is the
     * second route, and it opens at approval. Nobody is stranded, but the order
     * is approval first, reset second, and this comment used to imply
     * otherwise.
     *
     * That correction does NOT weaken the case for the wipe; if anything it
     * strengthens it. The password being discarded belongs to someone who never
     * proved the address, and the account is not being left unreachable — the
     * party who DID prove it is holding a working sign-in method the moment
     * approval lands. Keeping the password to preserve a recovery path would
     * preserve it for the wrong person.
     *
     * Without this, an attacker could park an unverified registration on any
     * address and wait for its real owner to sign in with Google, at which
     * point the attacker's password would unlock the victim's account.
     *
     * setPasswordHash() also stamps passwordChangedAt, which invalidates any
     * JWT issued before now — so a session the attacker somehow holds dies
     * here too.
     *
     * The alternative — refusing to link and creating a second account — was
     * rejected: it hands the attacker a cheap denial of service (park an
     * unverified registration on an address and its owner can never reach the
     * account that address names), and it strands the far more common
     * legitimate case, where the abandoned registration is the user's own.
     * Nothing is lost by claiming the row, because an unverified account holds
     * nothing but an address nobody has proven and a password nobody has used.
     */
    private function claimUnverifiedAccount(User $user): void
    {
        $user->setStatus(UserStatus::PendingApproval);
        $user->setPasswordHash(null, $this->clock->now());
    }

    /**
     * A first sign-in with no matching local account.
     *
     * Lands in pending_approval, skipping pending_verification: the double
     * opt-in mail exists to prove the address belongs to the person signing
     * up, and the provider has already proved exactly that. Membership is
     * still the admin's call — OAuth verifies identity, humans decide access.
     */
    private function createUser(OAuthIdentity $identity): User
    {
        $now = $this->clock->now();
        $user = new User($this->loginIdentifierFor($identity), $now);
        $user->setStatus(UserStatus::PendingApproval);

        $this->em->persist($user);

        return $user;
    }

    /**
     * What goes in User::$email — the login identifier — for a new account.
     *
     * Only an address this identity was allowed to LINK on may become one:
     * isLinkableByEmail() is the same gate, used twice on purpose, so the
     * invariant reads "User::$email is only ever an address somebody proved
     * they own".
     *
     * Refusing to link an unverified address is only half the rule. Taking it
     * as the new account's identifier anyway would let an attacker whose
     * provider allows arbitrary unverified addresses park
     * `admin@company.example` in the approval queue — approved on the strength
     * of how the address reads — and then share that one account with the real
     * owner, who can recover a password to it through the reset flow. So an
     * unlinkable claim is recorded on the UserIdentity row, where it is
     * visibly provider-reported, and never on the user.
     *
     * A private relay goes the same way for a different reason: it is a real
     * deliverable address, but it belongs to one (app, Apple user) pair rather
     * than to a person, so it is not an identity we want a login to hang on —
     * and it may already be held by a local account we just refused to link to.
     *
     * When the address IS linkable, findLinkTarget() has just established that
     * no account holds it. A concurrent request for the same address can still
     * lose that race and hit uniq_user_email; that surfaces as a 500 on a
     * request the user can retry, which is what RegistrationService does with
     * the same race.
     */
    private function loginIdentifierFor(OAuthIdentity $identity): string
    {
        if ($identity->isLinkableByEmail()) {
            \assert(null !== $identity->email);

            return $identity->email;
        }

        return $this->placeholderEmail($identity);
    }

    /**
     * A synthetic, non-routable address for an identity that has none we may
     * use — Apple returns the address only on the FIRST authorisation, so a
     * user who revokes and re-authorises arrives with `sub` and nothing else,
     * and User::$email is non-nullable and unique.
     *
     * `.invalid` is reserved by RFC 2606 precisely so it can never resolve, and
     * it is visibly not a real address to the admin reviewing the queue.
     *
     * One path does try to deliver to it, and it is worth naming rather than
     * pretending otherwise: approving such an account sends the "you're in"
     * mail, which AdminUserController addresses to User::$email. That send is
     * deferred to kernel.terminate and its failures are logged rather than
     * rethrown, so the bounce costs a log line and nothing else — the admin's
     * request still succeeds. Nothing else reaches AccountMailer without an
     * address a human typed: registration, verification and password reset all
     * start from one.
     *
     * Derived from the provider and subject rather than random, so it is
     * stable: the same identity reconstructs the same placeholder instead of
     * accumulating a new account per sign-in. The hash also keeps the subject
     * itself out of a column the admin UI displays.
     */
    private function placeholderEmail(OAuthIdentity $identity): string
    {
        return \sprintf(
            '%s-%s@oauth.invalid',
            $identity->provider,
            substr(hash('sha256', $identity->providerUserId), 0, 32),
        );
    }

    private function attach(User $user, OAuthIdentity $identity): void
    {
        $link = new UserIdentity(
            $user,
            $identity->provider,
            $identity->providerUserId,
            $this->clock->now(),
        );
        $link->setEmail($identity->email);

        $this->em->persist($link);
    }
}
