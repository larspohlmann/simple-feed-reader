<?php

declare(strict_types=1);

namespace App\Tests\Service\Profiling;

use App\Service\Grafana\GrafanaSettings;
use App\Service\Profiling\CollapsedProfile;
use App\Service\Profiling\ProfileSampler;
use App\Service\Profiling\ProfilingPolicy;
use App\Service\Profiling\PyroscopeEndpoint;
use PHPUnit\Framework\TestCase;

final class ProfilingPolicyTest extends TestCase
{
    public function testEnabledWhenAvailableAndOnWithAUrl(): void
    {
        $policy = $this->policy(available: true, enabled: true, url: 'http://pyroscope:4040');

        self::assertTrue($policy->isEnabled());
    }

    public function testDisabledWhenTheSamplerIsUnavailableAndSettingsAreNeverConsulted(): void
    {
        $settings = $this->createMock(GrafanaSettings::class);
        $settings->expects($this->never())->method('profilingEnabled');
        $policy = new ProfilingPolicy($settings, $this->sampler(false), $this->endpoint('http://pyroscope:4040'));

        self::assertFalse($policy->isEnabled());
    }

    public function testDisabledWhenOnButThePushUrlIsNull(): void
    {
        $policy = $this->policy(available: true, enabled: true, url: null);

        self::assertFalse($policy->isEnabled());
    }

    public function testDisabledWhenSettingsThrow(): void
    {
        $policy = new ProfilingPolicy($this->throwingSettings(), $this->sampler(true), $this->endpoint(null));

        self::assertFalse($policy->isEnabled());
    }

    private function policy(bool $available, bool $enabled, ?string $url): ProfilingPolicy
    {
        return new ProfilingPolicy($this->settings($enabled), $this->sampler($available), $this->endpoint($url));
    }

    private function settings(bool $enabled): GrafanaSettings
    {
        $settings = $this->createStub(GrafanaSettings::class);
        $settings->method('profilingEnabled')->willReturn($enabled);

        return $settings;
    }

    private function throwingSettings(): GrafanaSettings
    {
        $settings = $this->createStub(GrafanaSettings::class);
        $settings->method('profilingEnabled')->willThrowException(new \RuntimeException('boom'));

        return $settings;
    }

    private function sampler(bool $available): ProfileSampler
    {
        return new class ($available) implements ProfileSampler {
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

            public function stop(): ?CollapsedProfile
            {
                return null;
            }

            public function isRunning(): bool
            {
                return false;
            }
        };
    }

    private function endpoint(?string $url): PyroscopeEndpoint
    {
        return new class ($url) implements PyroscopeEndpoint {
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
