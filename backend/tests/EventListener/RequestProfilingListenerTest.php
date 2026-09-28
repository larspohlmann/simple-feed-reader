<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\RequestProfilingListener;
use App\Service\Logging\TraceContext\TraceContextInterface;
use App\Service\Profiling\CollapsedProfile;
use App\Service\Profiling\ProfileSampler\ProfileSamplerInterface;
use App\Service\Profiling\ProfilingConfigSource\ProfilingConfigSourceInterface;
use App\Service\Profiling\ProfilingPolicy;
use App\Service\Profiling\PyroscopeClient;
use App\Service\Profiling\PyroscopeEndpoint\PyroscopeEndpointInterface;
use App\Tests\Support\RecordingProfileSampler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
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
        $request = $this->routedRequest('api_entries_list');

        $listener->onKernelRequest($this->mainRequestEvent($request));
        $listener->onKernelTerminate($this->terminateEvent($request));

        self::assertSame([RequestProfilingListener::SAMPLE_PERIOD_SECONDS], $sampler->startedWithPeriods);
        self::assertCount(1, $pushes);
        self::assertStringContainsString('trace_id=' . self::TRACE_ID, $pushes[0]['name']);
        self::assertStringContainsString('span_id=' . self::SPAN_ID, $pushes[0]['name']);
        self::assertStringContainsString('route=api_entries_list', $pushes[0]['name']);
    }

    public function testARequestThatNeverReachedTheRouterIsLabelledUnrouted(): void
    {
        $sampler = new RecordingProfileSampler();
        $pushes = [];
        $listener = $this->listener($sampler, $pushes, enabled: true, traceId: self::TRACE_ID, spanId: self::SPAN_ID);

        $listener->onKernelRequest($this->mainRequestEvent());
        $listener->onKernelTerminate($this->terminateEvent());

        self::assertStringContainsString('route=unrouted', $pushes[0]['name']);
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

        $listener->onKernelTerminate($this->terminateEvent());

        self::assertSame([], $pushes);
    }

    public function testANullProfileFromStopPushesNothing(): void
    {
        $sampler = new RecordingProfileSampler(profile: null);
        $pushes = [];
        $listener = $this->listener($sampler, $pushes, enabled: true, traceId: self::TRACE_ID, spanId: self::SPAN_ID);

        $listener->onKernelRequest($this->mainRequestEvent());
        $listener->onKernelTerminate($this->terminateEvent());

        self::assertSame([], $pushes);
    }

    public function testATerminateThatThrowsSwallowsTheErrorAndResetsLabels(): void
    {
        $pushes = [];
        $sampler = $this->samplerThatThrowsOnFirstStopOnly();
        $listener = $this->listener(
            $sampler,
            $pushes,
            enabled: true,
            traceId: self::TRACE_ID,
            spanId: self::SPAN_ID,
        );
        $listener->onKernelRequest($this->mainRequestEvent());

        $listener->onKernelTerminate($this->terminateEvent());
        $listener->onKernelTerminate($this->terminateEvent());

        self::assertSame([], $pushes);
    }

    private function samplerThatThrowsOnFirstStopOnly(): ProfileSamplerInterface
    {
        return new class implements ProfileSamplerInterface {
            private int $stopCalls = 0;

            public function isAvailable(): bool
            {
                return true;
            }

            public function start(float $periodSeconds): void
            {
            }

            public function stop(): ?CollapsedProfile
            {
                ++$this->stopCalls;
                if (1 === $this->stopCalls) {
                    throw new \RuntimeException('sampler stop failed');
                }
                if ($this->stopCalls > 2) {
                    return null;
                }

                return new CollapsedProfile('main;work 1', 1, 1000, 1_700_000_000, 1_700_000_001);
            }

            public function isRunning(): bool
            {
                return false;
            }
        };
    }

    /** @param list<array{name: string}> $pushes */
    private function listener(
        ProfileSamplerInterface $sampler,
        array &$pushes,
        bool $enabled,
        ?string $traceId,
        ?string $spanId,
    ): RequestProfilingListener {
        $policy = new ProfilingPolicy($this->profilingConfig($enabled), $this->policySampler(), $this->endpoint());

        $trace = $this->createStub(TraceContextInterface::class);
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

    private function endpoint(): PyroscopeEndpointInterface
    {
        return new class implements PyroscopeEndpointInterface {
            public function pushUrl(): string
            {
                return 'http://pyroscope.test';
            }
        };
    }

    private function profilingConfig(bool $enabled): ProfilingConfigSourceInterface
    {
        $profilingConfig = $this->createStub(ProfilingConfigSourceInterface::class);
        $profilingConfig->method('profilingEnabled')->willReturn($enabled);

        return $profilingConfig;
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

    private function mainRequestEvent(Request $request = new Request()): RequestEvent
    {
        return new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function subRequestEvent(): RequestEvent
    {
        return new RequestEvent($this->kernel(), new Request(), HttpKernelInterface::SUB_REQUEST);
    }

    private function terminateEvent(Request $request = new Request()): TerminateEvent
    {
        return new TerminateEvent($this->kernel(), $request, new Response());
    }

    private function routedRequest(string $route): Request
    {
        $request = new Request();
        $request->attributes->set('_route', $route);

        return $request;
    }

    private function kernel(): HttpKernelInterface
    {
        return $this->createStub(HttpKernelInterface::class);
    }
}
