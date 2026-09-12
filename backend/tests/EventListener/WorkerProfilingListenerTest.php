<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\WorkerProfilingListener;
use App\Service\Grafana\GrafanaSettings;
use App\Service\Profiling\CollapsedProfile;
use App\Service\Profiling\ProfileSampler;
use App\Service\Profiling\ProfilingPolicy;
use App\Service\Profiling\PyroscopeClient;
use App\Service\Profiling\PyroscopeEndpoint;
use App\Tests\Support\TrackingProfileSampler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WorkerProfilingListenerTest extends TestCase
{
    public function testStartedWhileEnabledStartsTheSampler(): void
    {
        $pushes = [];
        $enabled = ['value' => true];
        $sampler = new TrackingProfileSampler();
        $listener = $this->listener($sampler, $pushes, $enabled, new MockClock('2026-09-12T00:00:00Z'));

        $listener->onWorkerStarted();

        self::assertSame([WorkerProfilingListener::SAMPLE_PERIOD_SECONDS], $sampler->startedWithPeriods);
    }

    public function testRunningBeforeTheFlushIntervalPushesNothing(): void
    {
        $pushes = [];
        $enabled = ['value' => true];
        $sampler = new TrackingProfileSampler();
        $listener = $this->listener($sampler, $pushes, $enabled, new MockClock('2026-09-12T00:00:00Z'));
        $listener->onWorkerStarted();

        $listener->onWorkerRunning();

        self::assertSame([], $pushes);
        self::assertCount(1, $sampler->startedWithPeriods);
    }

    public function testRunningAfterTheFlushIntervalPushesAndStartsAFreshWindow(): void
    {
        $pushes = [];
        $enabled = ['value' => true];
        $clock = new MockClock('2026-09-12T00:00:00Z');
        $sampler = new TrackingProfileSampler();
        $listener = $this->listener($sampler, $pushes, $enabled, $clock);
        $listener->onWorkerStarted();

        $clock->sleep(10);
        $listener->onWorkerRunning();

        self::assertCount(1, $pushes);
        self::assertStringContainsString('process=worker', $pushes[0]['name']);
        self::assertSame(
            [WorkerProfilingListener::SAMPLE_PERIOD_SECONDS, WorkerProfilingListener::SAMPLE_PERIOD_SECONDS],
            $sampler->startedWithPeriods,
        );
        self::assertTrue($sampler->isRunning());
    }

    public function testPolicyDisabledWhileRunningFlushesWithoutRestarting(): void
    {
        $pushes = [];
        $enabled = ['value' => true];
        $clock = new MockClock('2026-09-12T00:00:00Z');
        $sampler = new TrackingProfileSampler();
        $listener = $this->listener($sampler, $pushes, $enabled, $clock);
        $listener->onWorkerStarted();

        $enabled['value'] = false;
        $clock->sleep(30);
        $listener->onWorkerRunning();

        self::assertCount(1, $pushes);
        self::assertSame(1, $sampler->stopCalls);
        self::assertCount(1, $sampler->startedWithPeriods);
        self::assertFalse($sampler->isRunning());
    }

    public function testStoppedWhileRunningPushes(): void
    {
        $pushes = [];
        $enabled = ['value' => true];
        $sampler = new TrackingProfileSampler();
        $listener = $this->listener($sampler, $pushes, $enabled, new MockClock('2026-09-12T00:00:00Z'));
        $listener->onWorkerStarted();

        $listener->onWorkerStopped();

        self::assertCount(1, $pushes);
        self::assertSame(1, $sampler->stopCalls);
    }

    public function testStartedWhileDisabledDoesNothing(): void
    {
        $pushes = [];
        $enabled = ['value' => false];
        $sampler = new TrackingProfileSampler();
        $listener = $this->listener($sampler, $pushes, $enabled, new MockClock('2026-09-12T00:00:00Z'));

        $listener->onWorkerStarted();

        self::assertSame([], $sampler->startedWithPeriods);
        self::assertSame([], $pushes);
    }

    /**
     * @param list<array{name: string}> $pushes
     * @param array{value: bool} $enabled
     */
    private function listener(
        TrackingProfileSampler $sampler,
        array &$pushes,
        array &$enabled,
        MockClock $clock,
    ): WorkerProfilingListener {
        $settings = $this->createStub(GrafanaSettings::class);
        $settings->method('profilingEnabled')->willReturnCallback(
            static function () use (&$enabled): bool {
                return $enabled['value'];
            },
        );
        $policy = new ProfilingPolicy($settings, $this->policySampler(), $this->endpoint());

        $client = new PyroscopeClient(
            new MockHttpClient(function (string $method, string $url, array $options) use (&$pushes) {
                /** @var array{query: array{name: string}} $options */
                $pushes[] = $options['query'];

                return new MockResponse('', ['http_code' => 200]);
            }),
            $this->endpoint(),
        );

        return new WorkerProfilingListener($policy, $sampler, $client, $clock);
    }

    private function endpoint(): PyroscopeEndpoint
    {
        return new class implements PyroscopeEndpoint {
            public function pushUrl(): string
            {
                return 'http://pyroscope.test';
            }
        };
    }

    private function policySampler(): ProfileSampler
    {
        return new class implements ProfileSampler {
            public function isAvailable(): bool
            {
                return true;
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
}
