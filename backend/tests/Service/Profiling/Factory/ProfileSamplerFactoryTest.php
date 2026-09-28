<?php

declare(strict_types=1);

namespace App\Tests\Service\Profiling\Factory;

use App\Service\Profiling\Factory\ProfileSamplerFactory;
use App\Service\Profiling\ProfileSampler\ExcimerSampler;
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
