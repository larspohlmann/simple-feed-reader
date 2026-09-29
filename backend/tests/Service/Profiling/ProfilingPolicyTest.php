<?php

declare(strict_types=1);

namespace App\Tests\Service\Profiling;

use App\Service\Profiling\Model\CollapsedProfileModel;
use App\Service\Profiling\ProfileSampler\ProfileSamplerInterface;
use App\Service\Profiling\ProfilingConfigSource\ProfilingConfigSourceInterface;
use App\Service\Profiling\ProfilingPolicy;
use App\Service\Profiling\PyroscopeEndpoint\PyroscopeEndpointInterface;
use PHPUnit\Framework\TestCase;

final class ProfilingPolicyTest extends TestCase
{
    public function testEnabledWhenAvailableAndOnWithAUrl(): void
    {
        $policy = $this->policy(available: true, enabled: true, url: 'http://pyroscope:4040');

        self::assertTrue($policy->isEnabled());
    }

    public function testDisabledWhenTheSamplerIsUnavailableAndTheConfigIsNeverRead(): void
    {
        $config = $this->createMock(ProfilingConfigSourceInterface::class);
        $config->expects($this->never())->method('profilingEnabled');
        $policy = new ProfilingPolicy($config, $this->sampler(false), $this->endpoint('http://pyroscope:4040'));

        self::assertFalse($policy->isEnabled());
    }

    public function testDisabledWhenOnButThePushUrlIsNull(): void
    {
        self::assertFalse($this->policy(available: true, enabled: true, url: null)->isEnabled());
    }

    public function testDisabledWhenOffEvenWithAPushUrl(): void
    {
        self::assertFalse($this->policy(available: true, enabled: false, url: 'http://pyroscope:4040')->isEnabled());
    }

    public function testAConfigThatCannotBeReadMeansProfilingIsOff(): void
    {
        $config = $this->createStub(ProfilingConfigSourceInterface::class);
        $config->method('profilingEnabled')->willThrowException(new \RuntimeException('database gone'));
        $policy = new ProfilingPolicy($config, $this->sampler(true), $this->endpoint('http://pyroscope:4040'));

        self::assertFalse($policy->isEnabled());
    }

    public function testAPushUrlThatCannotBeReadMeansProfilingIsOff(): void
    {
        $endpoint = new class implements PyroscopeEndpointInterface {
            public function pushUrl(): ?string
            {
                throw new \RuntimeException('cache gone');
            }
        };
        $policy = new ProfilingPolicy($this->config(true), $this->sampler(true), $endpoint);

        self::assertFalse($policy->isEnabled());
    }

    private function policy(bool $available, bool $enabled, ?string $url): ProfilingPolicy
    {
        return new ProfilingPolicy($this->config($enabled), $this->sampler($available), $this->endpoint($url));
    }

    private function config(bool $enabled): ProfilingConfigSourceInterface
    {
        $config = $this->createStub(ProfilingConfigSourceInterface::class);
        $config->method('profilingEnabled')->willReturn($enabled);

        return $config;
    }

    private function sampler(bool $available): ProfileSamplerInterface
    {
        return new class ($available) implements ProfileSamplerInterface {
            public function __construct(private readonly bool $available)
            {
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function start(float $periodSeconds): void
            {
            }

            public function stop(): ?CollapsedProfileModel
            {
                return null;
            }

            public function isRunning(): bool
            {
                return false;
            }
        };
    }

    private function endpoint(?string $url): PyroscopeEndpointInterface
    {
        return new class ($url) implements PyroscopeEndpointInterface {
            public function __construct(private readonly ?string $url)
            {
            }

            public function pushUrl(): ?string
            {
                return $this->url;
            }
        };
    }
}
