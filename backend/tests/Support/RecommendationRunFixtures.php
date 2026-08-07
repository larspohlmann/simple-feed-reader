<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\AiProviderSettings;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The recommendation pipeline's two most-seeded fixtures: a ready-to-call AI
 * connection and an unread entry. RecommendationRunAdvancerTest and
 * AdvanceRecommendationRunsHandlerTest both drive real runs end to end, so
 * both need the exact same "an account that can actually call a provider"
 * and "an entry that actually shows up as a candidate" setup — a second
 * near-identical copy would drift the moment one of them changed a default.
 */
final readonly class RecommendationRunFixtures
{
    public function __construct(
        private EntityManagerInterface $em,
        private ApiKeyCipher $cipher,
    ) {
    }

    public function seedReadyAiSettings(User $user): void
    {
        $userId = $user->getId() ?? throw new \LogicException('Cannot seed AI settings for an unsaved account.');
        $sealed = $this->cipher->seal($userId, 'sk-throwaway1234');
        $now = new \DateTimeImmutable('2026-08-07 09:00:00');

        $settings = new AiProviderSettings($user, 'https://api.example.test/v1', $sealed, '1234', $now);
        $this->em->persist($settings);
        $settings->chooseModel('m', $now, 32768);
        $this->em->flush();
    }

    public function entry(Feed $feed, string $guid, string $published): Entry
    {
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.com/' . $guid,
            $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $entry->setPublishedAt(new \DateTimeImmutable($published));
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }
}
