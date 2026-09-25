<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

/**
 * Direct push where it is free (FPM, CLI); spool only on a cgi-fcgi web SAPI
 * that cannot detach the response.
 */
final readonly class LokiSinkFactory
{
    public function __construct(
        private DirectLokiSink $direct,
        private SpoolLokiSink $spool,
    ) {
    }

    public function create(): LokiSink
    {
        return match (self::selects(\PHP_SAPI, \function_exists('fastcgi_finish_request'))) {
            LokiDelivery::Spool => $this->spool,
            LokiDelivery::Direct => $this->direct,
        };
    }

    public static function selects(string $sapi, bool $canFinishRequest): LokiDelivery
    {
        if ('cli' === $sapi || $canFinishRequest) {
            return LokiDelivery::Direct;
        }

        return LokiDelivery::Spool;
    }
}
