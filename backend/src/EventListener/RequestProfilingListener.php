<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Logging\TraceContext;
use App\Service\Profiling\ProfileLabels;
use App\Service\Profiling\ProfileSampler;
use App\Service\Profiling\ProfilingPolicy;
use App\Service\Profiling\PyroscopeClient;
use OpenTelemetry\API\Trace\Span;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

#[AsEventListener(event: RequestEvent::class, method: 'onKernelRequest', priority: 4096)]
#[AsEventListener(event: TerminateEvent::class, method: 'onKernelTerminate', priority: 16)]
final class RequestProfilingListener
{
    public const float SAMPLE_PERIOD_SECONDS = 0.001;
    private const string PROFILE_ID_ATTRIBUTE = 'pyroscope.profile.id';

    private ?ProfileLabels $labels = null;

    public function __construct(
        private readonly ProfilingPolicy $policy,
        private readonly ProfileSampler $sampler,
        private readonly PyroscopeClient $client,
        private readonly TraceContext $trace,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->policy->isEnabled()) {
            return;
        }
        try {
            $traceId = $this->trace->traceId();
            $spanId = $this->trace->spanId();
            if (null === $traceId || null === $spanId) {
                return;
            }
            Span::getCurrent()->setAttribute(self::PROFILE_ID_ATTRIBUTE, $spanId);
            $this->labels = ProfileLabels::forWebRequest($traceId, $spanId);
            $this->sampler->start(self::SAMPLE_PERIOD_SECONDS);
        } catch (\Throwable) {
            // fail-open: profiling must never break a request
            $this->labels = null;
        }
    }

    public function onKernelTerminate(): void
    {
        if (null === $this->labels) {
            return;
        }
        $labels = $this->labels;
        $this->labels = null;
        try {
            $profile = $this->sampler->stop();
            if (null !== $profile) {
                $this->client->push($profile, $labels);
            }
        } catch (\Throwable) {
        }
    }
}
