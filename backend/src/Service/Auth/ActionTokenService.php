<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\ActionToken;
use App\Entity\User;
use App\Enum\TokenPurpose;
use App\Repository\ActionTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Issues and redeems the single-use tokens behind email verification and
 * password reset. The plaintext value exists only in the returned string and
 * the email built from it; the database holds nothing but a SHA-256 digest.
 */
final readonly class ActionTokenService
{
    private const LIFETIME = 'PT24H';

    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return string the plaintext token — the only time it is ever available
     */
    public function issue(User $user, TokenPurpose $purpose): string
    {
        $now = $this->clock->now();

        // 32 bytes of CSPRNG output, hex-encoded: 64 chars, URL-safe, and wide
        // enough that guessing is not a threat model. Generated before anything
        // is mutated, so a failure here cannot leave the user's outstanding
        // tokens retired with no replacement issued.
        $plain = bin2hex(random_bytes(32));

        // Retire outstanding tokens of the same purpose so a link that leaked
        // earlier stops working the moment a fresh one is requested.
        foreach ($this->repository()->findUnconsumedFor($user, $purpose) as $existing) {
            $existing->setConsumedAt($now);
        }

        $this->em->persist(new ActionToken(
            $user,
            $purpose,
            hash('sha256', $plain),
            $now->add(new \DateInterval(self::LIFETIME)),
            $now,
        ));
        $this->em->flush();

        return $plain;
    }

    /**
     * Redeems a token, marking it used. Returns null for every failure mode —
     * unknown, wrong purpose, already consumed, expired — because the caller
     * must not tell a guesser which of those it hit.
     */
    public function consume(string $plainToken, TokenPurpose $purpose): ?User
    {
        $token = $this->repository()->findOneByHashAndPurpose(hash('sha256', $plainToken), $purpose);

        if (null === $token || null !== $token->getConsumedAt()) {
            return null;
        }

        $now = $this->clock->now();
        if ($token->isExpiredAt($now)) {
            return null;
        }

        $token->setConsumedAt($now);
        $this->em->flush();

        return $token->getUser();
    }

    private function repository(): ActionTokenRepository
    {
        /** @var ActionTokenRepository $repository */
        $repository = $this->em->getRepository(ActionToken::class);

        return $repository;
    }
}
