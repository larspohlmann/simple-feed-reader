<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\WorkerProfilingListener;
use App\Service\Profiling\Model\CollapsedProfileModel;
use App\Service\Profiling\ProfileSampler\ProfileSamplerInterface;
use App\Service\Profiling\ProfilingConfigSource\ProfilingConfigSourceInterface;
use App\Service\Profiling\ProfilingPolicy;
use App\Service\Profiling\PyroscopeClient;
use App\Service\Profiling\PyroscopeEndpoint\PyroscopeEndpointInterface;
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
        $log = [];
        $sampler = new TrackingProfileSampler();
        $listener = $this->listener($sampler, $pushes, $enabled, new MockClock('2026-09-12T00:00:00Z'), $log);

        $listener->onWorkerStarted();

        self::assertSame([WorkerProfilingListener::SAMPLE_PERIOD_SECONDS], $sampler->startedWithPeriods);
        self::assertSame(['refresh', 'profilingEnabled'], $log);
    }

    public function testRunningBeforeTheFlushIntervalPushesNothing(): void
    {
        $pushes = [];
        $enabled = ['value' => true];
        $log = [];
        $sampler = new TrackingProfileSampler();
        $listener = $this->listener($sampler, $pushes, $enabled, new MockClock('2026-09-12T00:00:00Z'), $log);
        $listener->onWorkerStarted();

        $listener->onWorkerRunning();

        self::assertSame([], $pushes);
        self::assertCount(1, $sampler->startedWithPeriods);
    }

    public function testRunningAfterTheFlushIntervalPushesAndStartsAFreshWindow(): void
    {
        $pushes = [];
        $enabled = ['value' => true];
        $log = [];
        $clock = new MockClock('2026-09-12T00:00:00Z');
        $sampler = new TrackingProfileSampler();
        $listener = $this->listener($sampler, $pushes, $enabled, $clock, $log);
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

    public function testRunningRechecksTheToggleEveryThirtySeconds(): void
    {
        $pushes = [];
        $enabled = ['value' => true];
        $log = [];
        $clock = new MockClock('2026-09-12T00:00:00Z');
        $sampler = new TrackingProfileSampler();
        $listener = $this->listener($sampler, $pushes, $enabled, $clock, $log);
        $listener->onWorkerStarted();

        $clock->sleep(30);
        $listener->onWorkerRunning();

        self::assertSame(
            ['refresh', 'profilingEnabled', 'refresh', 'profilingEnabled'],
            $log,
        );
    }

    public function testPolicyDisabledWhileRunningFlushesWithoutRestarting(): void
    {
        $pushes = [];
        $enabled = ['value' => true];
        $log = [];
        $clock = new MockClock('2026-09-12T00:00:00Z');
        $sampler = new TrackingProfileSampler();
        $listener = $this->listener($sampler, $pushes, $enabled, $clock, $log);
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
        $log = [];
        $sampler = new TrackingProfileSampler();
        $listener = $this->listener($sampler, $pushes, $enabled, new MockClock('2026-09-12T00:00:00Z'), $log);
        $listener->onWorkerStarted();

        $listener->onWorkerStopped();

        self::assertCount(1, $pushes);
        self::assertSame(1, $sampler->stopCalls);
    }

    public function testStartedWhileDisabledDoesNothing(): void
    {
        $pushes = [];
        $enabled = ['value' => false];
        $log = [];
        $sampler = new TrackingProfileSampler();
        $listener = $this->listener($sampler, $pushes, $enabled, new MockClock('2026-09-12T00:00:00Z'), $log);

        $listener->onWorkerStarted();

        self::assertSame([], $sampler->startedWithPeriods);
        self::assertSame([], $pushes);
    }

    public function testAThrowingSamplerNeverCrashesWorkerStarted(): void
    {
        $pushes = [];
        $enabled = ['value' => true];
        $log = [];
        $listener = $this->listener(
            $this->throwingSampler(),
            $pushes,
            $enabled,
            new MockClock('2026-09-12T00:00:00Z'),
            $log,
        );

        $listener->onWorkerStarted();

        $this->addToAssertionCount(1);
    }

    public function testAThrowingSamplerNeverCrashesWorkerStopped(): void
    {
        $pushes = [];
        $enabled = ['value' => true];
        $log = [];
        $listener = $this->listener(
            $this->throwingSampler(),
            $pushes,
            $enabled,
            new MockClock('2026-09-12T00:00:00Z'),
            $log,
        );

        $listener->onWorkerStopped();

        $this->addToAssertionCount(1);
    }

    private function throwingSampler(): ProfileSamplerInterface
    {
        return new class implements ProfileSamplerInterface {
            public function isAvailable(): bool
            {
                return true;
            }

            public function start(float $periodSeconds): void
            {
                throw new \RuntimeException('sampler start failed');
            }

            public function stop(): ?CollapsedProfileModel
            {
                throw new \RuntimeException('sampler stop failed');
            }

            public function isRunning(): bool
            {
                return false;
            }
        };
    }

    /**
     * @param list<array{name: string}> $pushes
     * @param array{value: bool} $enabled
     * @param list<string> $log
     */
    private function listener(
        ProfileSamplerInterface $sampler,
        array &$pushes,
        array &$enabled,
        MockClock $clock,
        array &$log,
    ): WorkerProfilingListener {
        $profilingConfig = $this->createStub(ProfilingConfigSourceInterface::class);
        $profilingConfig->method('refresh')->willReturnCallback(static function () use (&$log): void {
            $log[] = 'refresh';
        });
        $profilingConfig->method('profilingEnabled')->willReturnCallback(
            static function () use (&$enabled, &$log): bool {
                $log[] = 'profilingEnabled';

                return $enabled['value'];
            },
        );
        $policy = new ProfilingPolicy($profilingConfig, $this->policySampler(), $this->endpoint());

        $client = new PyroscopeClient(
            new MockHttpClient(function (string $method, string $url, array $options) use (&$pushes) {
                /** @var array{query: array{name: string}} $options */
                $pushes[] = $options['query'];

                return new MockResponse('', ['http_code' => 200]);
            }),
            $this->endpoint(),
        );

        return new WorkerProfilingListener($policy, $sampler, $client, $clock, $profilingConfig);
    }

    private function endpoint(): PyroscopeEndpointInterface
    {
        return new class implements PyroscopeEndpointInterface {
            public function pushUrl(): string
            {
                return 'http://pyroscope.test';
            }
        };
    }

    private function policySampler(): ProfileSamplerInterface
    {
        return new class implements ProfileSamplerInterface {
            public function isAvailable(): bool
            {
                return true;
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
}
