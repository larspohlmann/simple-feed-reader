<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\RecommendationRun;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\TickContext;
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

        return new TickContext($run, $connection, $resolver->forUser($user), TickDriver::Poll);
    }
}
