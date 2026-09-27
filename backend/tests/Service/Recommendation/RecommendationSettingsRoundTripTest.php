<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\RecommendationSettings;
use App\Entity\RecommendationSettingsValues;
use App\Entity\User;
use App\Enum\RecommendationBatchSize;
use App\Service\Recommendation\RecommendationSettingsResolver;
use App\Service\Recommendation\RecommendationSettingsWriter;
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
            favoritesCap: RecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: RecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: RecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: RecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
            lookbackDays: $lookbackDays,
            picksLimit: RecommendationSettings::DEFAULT_PICKS_LIMIT,
            contextWindow: null,
            batchSize: RecommendationBatchSize::Medium,
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

    public function testTheLookbackWindowPersistsAndResolves(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        self::assertInstanceOf(RecommendationSettingsWriter::class, $writer);
        $resolver = self::getContainer()->get(RecommendationSettingsResolver::class);
        self::assertInstanceOf(RecommendationSettingsResolver::class, $resolver);

        $user = new User('lookback-roundtrip@example.com', new \DateTimeImmutable());
        $em->persist($user);
        $em->flush();

        // No row at all resolves to the default, not to zero.
        self::assertSame(2, $resolver->forUser($user)->lookbackDays);

        $writer->save($user, $this->values(null, 5));

        self::assertSame(5, $resolver->forUser($user)->lookbackDays);
    }
}
