<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\User;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationSettingsResolver;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Service\Recommendation\RecommendationSettingsWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecommendationSettingsRoundTripTest extends KernelTestCase
{
    private function values(?int $autoGenerateIntervalHours): RecommendationSettingsValues
    {
        return new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: EffectiveRecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
            picksLimit: EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
            contextWindow: null,
            batchCount: null,
            debugEnabled: false,
            autoGenerateIntervalHours: $autoGenerateIntervalHours,
        );
    }

    public function testTheIntervalPersistsAndResolves(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        self::assertInstanceOf(RecommendationSettingsWriter::class, $writer);
        $resolver = self::getContainer()->get(RecommendationSettingsResolver::class);
        self::assertInstanceOf(RecommendationSettingsResolver::class, $resolver);

        $user = new User('interval-roundtrip@example.com', new \DateTimeImmutable());
        $em->persist($user);
        $em->flush();

        self::assertNull($resolver->forUser($user)->autoGenerateIntervalHours);

        $writer->save($user, $this->values(3));

        self::assertSame(3, $resolver->forUser($user)->autoGenerateIntervalHours);
    }
}
