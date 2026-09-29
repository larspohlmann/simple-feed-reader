<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Settings;

use App\Entity\RecommendationHistoryCaps;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationSettings;
use App\Entity\RecommendationSettingsValues;
use App\Entity\User;
use App\Enum\RecommendationBatchSize;
use App\Service\Recommendation\Settings\RecommendationSettingsResolver;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecommendationSettingsRoundTripTest extends KernelTestCase
{
    private function values(
        ?int $autoGenerateIntervalHours,
        int $lookbackDays = RecommendationSettings::DEFAULT_LOOKBACK_DAYS,
    ): RecommendationSettingsValues {
        return new RecommendationSettingsValues(
            guidancePrompt: null,
            historyCaps: RecommendationHistoryCaps::defaults(),
            poolLimits: new RecommendationPoolLimits(
                RecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
                $lookbackDays,
                RecommendationSettings::DEFAULT_PICKS_LIMIT,
            ),
            contextWindow: null,
            batchSize: RecommendationBatchSize::Medium,
            debugEnabled: false,
            autoGenerateIntervalHours: $autoGenerateIntervalHours,
        );
    }

    public function testTheIntervalPersistsAndResolves(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        self::assertInstanceOf(RecommendationSettingsWriter::class, $writer);
        $resolver = self::getContainer()->get(RecommendationSettingsResolver::class);
        self::assertInstanceOf(RecommendationSettingsResolver::class, $resolver);

        $user = new User('interval-roundtrip@example.com', new \DateTimeImmutable());
        $entityManager->persist($user);
        $entityManager->flush();

        self::assertNull($resolver->forUser($user)->autoGenerateIntervalHours);

        $writer->save($user, $this->values(3));

        self::assertSame(3, $resolver->forUser($user)->autoGenerateIntervalHours);
    }

    public function testTheLookbackWindowPersistsAndResolves(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        self::assertInstanceOf(RecommendationSettingsWriter::class, $writer);
        $resolver = self::getContainer()->get(RecommendationSettingsResolver::class);
        self::assertInstanceOf(RecommendationSettingsResolver::class, $resolver);

        $user = new User('lookback-roundtrip@example.com', new \DateTimeImmutable());
        $entityManager->persist($user);
        $entityManager->flush();

        // No row at all resolves to the default, not to zero.
        self::assertSame(2, $resolver->forUser($user)->poolLimits->lookbackDays);

        $writer->save($user, $this->values(null, 5));

        self::assertSame(5, $resolver->forUser($user)->poolLimits->lookbackDays);
    }
}
