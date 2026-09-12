<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

final class LokiPushHandler extends AbstractProcessingHandler
{
    public const string CLIENT_ERRORS_CHANNEL = 'client_errors';

    private const array SOURCE_BY_CHANNEL = [self::CLIENT_ERRORS_CHANNEL => 'frontend'];

    /** @var list<array{ts: string, line: string, labels: array<string, string>}> */
    private array $buffer = [];

    public function __construct(
        private readonly LokiSink $sink,
        private readonly string $appLabel,
        private readonly string $envLabel,
        Level $level = Level::Info,
        private readonly int $flushThreshold = 100,
    ) {
        parent::__construct($level, true);
        $this->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, false, false, true));
    }

    protected function write(LogRecord $record): void
    {
        $this->buffer[] = [
            'ts' => $this->nanoTimestamp($record->datetime),
            'line' => $this->formattedLine($record),
            'labels' => [
                'app' => $this->appLabel,
                'env' => $this->envLabel,
                'channel' => $record->channel,
                'level' => strtolower($record->level->getName()),
                'source' => self::SOURCE_BY_CHANNEL[$record->channel] ?? 'backend',
            ],
        ];

        if (count($this->buffer) >= $this->flushThreshold) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ([] === $this->buffer) {
            return;
        }

        $lines = $this->buffer;
        $this->buffer = [];
        $this->sink->write($lines);
    }

    public function reset(): void
    {
        $this->flush();
        parent::reset();
    }

    public function close(): void
    {
        $this->flush();
        parent::close();
    }

    private function nanoTimestamp(\DateTimeInterface $time): string
    {
        return $time->format('U') . str_pad($time->format('u'), 6, '0', STR_PAD_RIGHT) . '000';
    }

    private function formattedLine(LogRecord $record): string
    {
        if (!is_string($record->formatted)) {
            throw new \LogicException('LokiPushHandler requires a formatter that produces a string.');
        }

        return $record->formatted;
    }
}
