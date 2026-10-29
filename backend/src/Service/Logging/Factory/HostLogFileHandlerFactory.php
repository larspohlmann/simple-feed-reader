<?php

declare(strict_types=1);

namespace App\Service\Logging\Factory;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;

/**
 * One daily log file per machine: appends from several hosts to one file on Strato's
 * network storage interleave and leave NUL runs (#1878); appends from one host do not.
 */
final readonly class HostLogFileHandlerFactory
{
    private const int KEPT_DAYS = 7;

    public function __construct(
        private string $logsDirectory,
        private string $environment,
        private FormatterInterface $formatter,
    ) {
    }

    public function create(): RotatingFileHandler
    {
        $handler = new RotatingFileHandler(
            self::logFileFor($this->logsDirectory, $this->environment, gethostname()),
            self::KEPT_DAYS,
            Level::Info,
        );
        $handler->setFormatter($this->formatter);

        return $handler;
    }

    public static function logFileFor(string $logsDirectory, string $environment, string|false $host): string
    {
        if ($host === false || $host === '') {
            return \sprintf('%s/%s.log', $logsDirectory, $environment);
        }

        return \sprintf('%s/%s-%s.log', $logsDirectory, $environment, $host);
    }
}
