<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\ActionToken;
use App\Entity\User;
use App\Enum\TokenPurpose;
use App\Repository\ActionTokenRepository;
use App\Service\Auth\Exception\InvalidTokenException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Random\RandomException;

/**
 * Issues and redeems the single-use tokens behind email verification and
 * password reset. The plaintext value exists only in the returned string and
 * the email built from it; the database holds nothing but a SHA-256 digest.
 */
final readonly class ActionTokenService
{
    private const string LIFETIME = 'PT24H';

    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return string the plaintext token — the only time it is ever available
     * @throws RandomException
     */
    public function issue(User $user, TokenPurpose $purpose): string
    {
        $now = $this->clock->now();

        // 32 bytes of CSPRNG output, hex-encoded: 64 URL-safe chars, far too wide
        // to guess. Generated before anything is mutated, so a failure here never
        // retires the user's tokens without issuing a replacement.
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

    /** Every failure mode is the same exception, so a guesser cannot tell which one it hit. */
    public function consume(string $plainToken, TokenPurpose $purpose): User
    {
        $token = $this->repository()->findOneByHashAndPurpose(hash('sha256', $plainToken), $purpose);
        $now = $this->clock->now();

        if (null === $token || null !== $token->getConsumedAt() || $token->isExpiredAt($now)) {
            throw new InvalidTokenException();
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
