<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Dto\Admin\GrafanaSettingsRequest;
use App\Service\Grafana\GrafanaSettings;
use App\Service\Profiling\CollapsedProfile;
use App\Service\Profiling\ProfileSampler;
use App\Service\Profiling\PyroscopeClient;
use App\Service\Profiling\PyroscopeEndpoint;
use App\Tests\Support\ApiTestCase;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RequestProfilingTest extends ApiTestCase
{
    /** @var list<array<string, mixed>> */
    private array $pyroscopePushes = [];

    public function testARealSpanIsTaggedWithTheProfileIdAndExactlyOnePushIsSent(): void
    {
        $client = self::createClient();
        $this->installPyroscopeCapture();
        $this->enableProfilingWithPushUrl('http://pyroscope.test');

        $exporter = new InMemoryExporter();
        $tracer = (new TracerProvider(new SimpleSpanProcessor($exporter)))->getTracer('test');
        $span = $tracer->spanBuilder('functional-test-span')->setSpanKind(SpanKind::KIND_SERVER)->startSpan();
        $scope = $span->activate();

        $traceId = $span->getContext()->getTraceId();
        $spanId = $span->getContext()->getSpanId();

        $client->request('GET', '/api/health');

        $scope->detach();
        $span->end();

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->pyroscopePushes);
        /** @var array{query: array{name: string}} $push */
        $push = $this->pyroscopePushes[0];
        self::assertStringContainsString('trace_id=' . $traceId, $push['query']['name']);
        self::assertStringContainsString('span_id=' . $spanId, $push['query']['name']);

        /** @var ImmutableSpan $recordedSpan */
        $recordedSpan = $exporter->getSpans()[0];
        self::assertSame($spanId, $recordedSpan->getAttributes()->get('pyroscope.profile.id'));
    }

    private function installPyroscopeCapture(): void
    {
        $this->pyroscopePushes = [];
        $endpoint = new class implements PyroscopeEndpoint {
            public function pushUrl(): string
            {
                return 'http://pyroscope.test';
            }
        };
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            /** @var array<string, mixed> $options */
            $this->pyroscopePushes[] = $options;

            return new MockResponse('', ['http_code' => 204]);
        });
        self::getContainer()->set(PyroscopeClient::class, new PyroscopeClient($http, $endpoint));
        self::getContainer()->set(ProfileSampler::class, $this->fixedSampler());
    }

    private function fixedSampler(): ProfileSampler
    {
        return new class implements ProfileSampler {
            private bool $running = false;

            public function isAvailable(): bool
            {
                return true;
            }

            public function start(float $periodSeconds): void
            {
                $this->running = true;
            }

            public function stop(): CollapsedProfile
            {
                $this->running = false;

                return new CollapsedProfile('main;work 1', 1, 1000, 1_700_000_000, 1_700_000_001);
            }

            public function isRunning(): bool
            {
                return $this->running;
            }
        };
    }

    private function enableProfilingWithPushUrl(string $pushUrl): void
    {
        /** @var GrafanaSettings $settings */
        $settings = self::getContainer()->get(GrafanaSettings::class);
        $settings->update(new GrafanaSettingsRequest(profilingEnabled: true, pyroscopePushUrl: $pushUrl));
    }
}
