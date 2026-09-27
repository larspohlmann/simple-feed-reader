<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Service\Recommendation\Run\RecommendationWaveConcurrency;
use App\Tests\Support\AiProviderSettingsFactory;
use PHPUnit\Framework\TestCase;

final class RecommendationWaveConcurrencyTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
    }

    public function testTheCapIsOneWhenTheConnectionStoresAConcurrencyOfZero(): void
    {
        self::assertSame(1, new RecommendationWaveConcurrency()->cap($this->runningRun(), $this->connection(0)));
    }

    public function testTheCapIsTheHalvedConcurrencyAfterARateLimit(): void
    {
        $run = $this->runningRun();
        $connection = $this->connection(4);
        $waveConcurrency = new RecommendationWaveConcurrency();

        $waveConcurrency->halve($run, $connection);

        self::assertSame(2, $waveConcurrency->cap($run, $connection));
    }

    private function runningRun(): RecommendationRun
    {
        $run = new RecommendationRun($this->user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $run->snapshot([[1], [2]]);

        return $run;
    }

    private function connection(int $batchConcurrency): AiProviderSettings
    {
        $connection = AiProviderSettingsFactory::build($this->user);
        $connection->setBatchConcurrency($batchConcurrency);

        return $connection;
    }
}
