<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Simulates an AiProviderSettings row ending up under the wrong account — a
 * cross-account key-mismatch scenario several tests need to prove a stored
 * key can never be opened under an id it was not sealed for. Goes around
 * AiProviderConfigurator (the only real writer of this association) via bulk
 * DQL, which is the only way to reach a state the configurator itself would
 * refuse to create.
 *
 * moveOwnership() and pointActiveAt() are separate calls, not one method,
 * because callers need the active pointer applied two different ways: a
 * caller that reloads $to afterward (a fresh User instance queried from the
 * database) is satisfied by pointActiveAt()'s own DQL write, while a caller
 * that keeps driving the exact $to instance it already holds has to set the
 * pointer on that instance directly — em->clear() detaches it rather than
 * refreshing it, so a bulk DQL write alone would never become visible to it.
 */
final readonly class AiSettingsRowMover
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Moves the row's ownership FK from $from to $to, then hands back the
     * same row freshly queried under its new owner — the identity map is
     * cleared as part of this, so anything either account was attached to
     * before this call is now detached.
     */
    public function moveOwnership(User $from, User $to): AiProviderSettings
    {
        $this->em->createQuery(
            sprintf('UPDATE %s s SET s.user = :to WHERE s.user = :from', AiProviderSettings::class),
        )->execute(['to' => $to, 'from' => $from]);
        $this->em->clear();

        $moved = $this->em->getRepository(AiProviderSettings::class)->findOneBy(['user' => $to]);

        if (!$moved instanceof AiProviderSettings) {
            throw new \LogicException('Expected a moved AiProviderSettings row after the ownership move.');
        }

        return $moved;
    }

    /**
     * Points $to's active configuration at $settings at the database level.
     * Only useful to a caller that reloads $to afterward — see the class doc.
     */
    public function pointActiveAt(User $to, AiProviderSettings $settings): void
    {
        $this->em->createQuery(
            sprintf('UPDATE %s u SET u.activeAiProviderSettings = :settings WHERE u = :to', User::class),
        )->execute(['settings' => $settings, 'to' => $to]);
        $this->em->clear();
    }
}
