<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Operator-driven password reset for a mailless instance (issue #230), where the
 * self-service email flow cannot run. Shared by the CLI command and the admin
 * endpoint. Stamping passwordChangedAt evicts every JWT issued before the reset
 * (see PasswordChangeTokenInvalidator), so a leaked session dies here too.
 */
final readonly class PasswordResetter
{
    private const int GENERATED_LENGTH = 24;

    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private ClockInterface $clock,
    ) {
    }

    public function setPassword(User $user, string $plainPassword): void
    {
        $user->setPasswordHash($this->hasher->hashPassword($user, $plainPassword), $this->clock->now());
        $this->em->flush();
    }

    /**
     * Sets a fresh random password and returns it once. url-safe base64 so the
     * operator can relay it without escaping surprises.
     */
    public function generateAndSet(User $user): string
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(self::GENERATED_LENGTH)), '+/', '-_'), '=');
        $this->setPassword($user, $plain);

        return $plain;
    }
}
