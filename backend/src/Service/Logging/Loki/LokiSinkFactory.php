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
        return 'spool' === self::selects(\PHP_SAPI, \function_exists('fastcgi_finish_request'))
            ? $this->spool
            : $this->direct;
    }

    public static function selects(string $sapi, bool $canFinishRequest): string
    {
        if ('cli' === $sapi) {
            return 'direct';
        }

        return $canFinishRequest ? 'direct' : 'spool';
    }
}
