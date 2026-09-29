<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moves an AiProviderSettings row to another account by bulk DQL, a state AiProviderConfigurator refuses to create, so
 * tests prove a key never opens under an id it was not sealed for. pointActiveAt() writes only the row: a caller that
 * keeps its $to instance sets the pointer on it too, since em->clear() detaches it rather than refreshing it.
 */
final readonly class AiSettingsRowMover
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** Returns the row re-read under its new owner; the identity map is cleared, so all held entities are detached. */
    public function moveOwnership(User $from, User $to): AiProviderSettings
    {
        $this->entityManager->createQuery(
            sprintf('UPDATE %s s SET s.user = :to WHERE s.user = :from', AiProviderSettings::class),
        )->execute(['to' => $to, 'from' => $from]);
        $this->entityManager->clear();

        $moved = $this->entityManager->getRepository(AiProviderSettings::class)->findOneBy(['user' => $to]);

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
        $this->entityManager->createQuery(
            sprintf('UPDATE %s u SET u.activeAiProviderSettings = :settings WHERE u = :to', User::class),
        )->execute(['settings' => $settings, 'to' => $to]);
        $this->entityManager->clear();
    }
}
