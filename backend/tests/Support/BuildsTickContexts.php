<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\RecommendationRun;
use App\Enum\RecommendationEngineKind;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\Pass\TickContext;
use App\Service\Recommendation\Settings\RecommendationSettingsResolver;

trait BuildsTickContexts
{
    private function tick(RecommendationRun $run): TickContext
    {
        $user = $run->getUser();
        $connection = $user->getActiveAiProviderSettings();
        self::assertNotNull($connection);
        $resolver = self::getContainer()->get(RecommendationSettingsResolver::class);
        self::assertInstanceOf(RecommendationSettingsResolver::class, $resolver);

        $engines = self::getContainer()->get(RecommendationEngineResolver::class);
        self::assertInstanceOf(RecommendationEngineResolver::class, $engines);

        return new TickContext(
            $run,
            $connection,
            $engines->kindFor($connection),
            $resolver->forUser($user),
            TickDriver::Poll,
        );
    }

    private function tickOfKind(RecommendationRun $run, RecommendationEngineKind $kind): TickContext
    {
        $tick = $this->tick($run);

        return new TickContext($run, $tick->connection, $kind, $tick->settings, $tick->driver);
    }
}
