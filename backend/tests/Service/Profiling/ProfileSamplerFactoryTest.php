<?php

declare(strict_types=1);

namespace App\Tests\Service\Profiling;

use App\Service\Profiling\ExcimerSampler;
use App\Service\Profiling\ProfileSamplerFactory;
use PHPUnit\Framework\TestCase;

final class ProfileSamplerFactoryTest extends TestCase
{
    public function testPicksTheExcimerAdapterExactlyWhenTheExtensionIsLoaded(): void
    {
        $sampler = (new ProfileSamplerFactory())->create();

        self::assertSame(extension_loaded('excimer'), $sampler instanceof ExcimerSampler);
        self::assertSame(extension_loaded('excimer'), $sampler->isAvailable());
    }
}
