<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\RequestProfilingListener;
use App\Service\Grafana\GrafanaSettings;
use App\Service\Logging\TraceContext;
use App\Service\Profiling\CollapsedProfile;
use App\Service\Profiling\ProfileSampler;
use App\Service\Profiling\ProfilingPolicy;
use App\Service\Profiling\PyroscopeClient;
use App\Service\Profiling\PyroscopeEndpoint;
use App\Tests\Support\RecordingProfileSampler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestProfilingListenerTest extends TestCase
{
    private const string TRACE_ID = 'abc123';
    private const string SPAN_ID = 'def456';

    public function testMainRequestWithinPolicyStartsTheSamplerAndTerminatePushesWithSpanLabels(): void
    {
        $sampler = new RecordingProfileSampler();
        $pushes = [];
        $listener = $this->listener($sampler, $pushes, enabled: true, traceId: self::TRACE_ID, spanId: self::SPAN_ID);

        $listener->onKernelRequest($this->mainRequestEvent());
        $listener->onKernelTerminate();

        self::assertSame([RequestProfilingListener::SAMPLE_PERIOD_SECONDS], $sampler->startedWithPeriods);
        self::assertCount(1, $pushes);
        self::assertStringContainsString('trace_id=' . self::TRACE_ID, $pushes[0]['name']);
        self::assertStringContainsString('span_id=' . self::SPAN_ID, $pushes[0]['name']);
    }

    public function testASubRequestNeverStartsTheSampler(): void
    {
        $sampler = new RecordingProfileSampler();
        $pushes = [];
        $listener = $this->listener($sampler, $pushes, enabled: true, traceId: self::TRACE_ID, spanId: self::SPAN_ID);

        $listener->onKernelRequest($this->subRequestEvent());

        self::assertSame([], $sampler->startedWithPeriods);
    }

    public function testAPolicyThatIsDisabledNeverStartsTheSampler(): void
    {
        $sampler = new RecordingProfileSampler();
        $pushes = [];
        $listener = $this->listener($sampler, $pushes, enabled: false, traceId: self::TRACE_ID, spanId: self::SPAN_ID);

        $listener->onKernelRequest($this->mainRequestEvent());

        self::assertSame([], $sampler->startedWithPeriods);
    }

    public function testMissingTraceIdsNeverStartTheSampler(): void
    {
        $sampler = new RecordingProfileSampler();
        $pushes = [];
        $listener = $this->listener($sampler, $pushes, enabled: true, traceId: null, spanId: null);

        $listener->onKernelRequest($this->mainRequestEvent());

        self::assertSame([], $sampler->startedWithPeriods);
    }

    public function testTerminateWithoutAPriorStartPushesNothing(): void
    {
        $sampler = new RecordingProfileSampler();
        $pushes = [];
        $listener = $this->listener($sampler, $pushes, enabled: true, traceId: self::TRACE_ID, spanId: self::SPAN_ID);

        $listener->onKernelTerminate();

        self::assertSame([], $pushes);
    }

    public function testANullProfileFromStopPushesNothing(): void
    {
        $sampler = new RecordingProfileSampler(profile: null);
        $pushes = [];
        $listener = $this->listener($sampler, $pushes, enabled: true, traceId: self::TRACE_ID, spanId: self::SPAN_ID);

        $listener->onKernelRequest($this->mainRequestEvent());
        $listener->onKernelTerminate();

        self::assertSame([], $pushes);
    }

    /** @param list<array{name: string}> $pushes */
    private function listener(
        RecordingProfileSampler $sampler,
        array &$pushes,
        bool $enabled,
        ?string $traceId,
        ?string $spanId,
    ): RequestProfilingListener {
        $policy = new ProfilingPolicy($this->settings($enabled), $this->policySampler(), $this->endpoint());

        $trace = $this->createStub(TraceContext::class);
        $trace->method('traceId')->willReturn($traceId);
        $trace->method('spanId')->willReturn($spanId);

        $client = new PyroscopeClient(
            new MockHttpClient(function (string $method, string $url, array $options) use (&$pushes) {
                /** @var array{query: array{name: string}} $options */
                $pushes[] = $options['query'];

                return new MockResponse('', ['http_code' => 200]);
            }),
            $this->endpoint(),
        );

        return new RequestProfilingListener($policy, $sampler, $client, $trace);
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

    private function settings(bool $enabled): GrafanaSettings
    {
        $settings = $this->createStub(GrafanaSettings::class);
        $settings->method('profilingEnabled')->willReturn($enabled);

        return $settings;
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

    private function mainRequestEvent(): RequestEvent
    {
        return new RequestEvent($this->kernel(), new Request(), HttpKernelInterface::MAIN_REQUEST);
    }

    private function subRequestEvent(): RequestEvent
    {
        return new RequestEvent($this->kernel(), new Request(), HttpKernelInterface::SUB_REQUEST);
    }

    private function kernel(): HttpKernelInterface
    {
        return $this->createStub(HttpKernelInterface::class);
    }
}
