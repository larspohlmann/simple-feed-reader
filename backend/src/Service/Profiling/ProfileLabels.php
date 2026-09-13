<?php

declare(strict_types=1);

namespace App\Service\Profiling;

final readonly class ProfileLabels
{
    private const string SERVICE_NAME = 'simple-feed-reader';

    /** @param array<string, string> $labels */
    private function __construct(private array $labels)
    {
    }

    public static function forWebRequest(string $traceId, string $spanId): self
    {
        return new self([
            'service_name' => self::SERVICE_NAME,
            'process' => 'web',
            'trace_id' => $traceId,
            'span_id' => $spanId,
        ]);
    }

    public static function forWorker(): self
    {
        return new self(['service_name' => self::SERVICE_NAME, 'process' => 'worker']);
    }

    public function withRoute(string $route): self
    {
        return new self([...$this->labels, 'route' => $route]);
    }

    public function toNameParameter(string $application): string
    {
        $pairs = array_map(
            static fn (string $key, string $value): string => $key . '=' . $value,
            array_keys($this->labels),
            $this->labels,
        );

        return $application . '{' . implode(',', $pairs) . '}';
    }
}
