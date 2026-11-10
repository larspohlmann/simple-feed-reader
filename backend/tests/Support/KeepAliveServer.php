<?php

declare(strict_types=1);

namespace App\Tests\Support;

final class KeepAliveServer
{
    /** @param resource $process */
    private function __construct(private $process)
    {
    }

    public static function start(string $ip, int $port, string $name): ?self
    {
        $process = proc_open(
            [\PHP_BINARY, __DIR__ . '/keep-alive-server.php', $ip, (string) $port, $name],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!\is_resource($process)) {
            return null;
        }
        if ("ready\n" !== fgets($pipes[1])) {
            proc_terminate($process);

            return null;
        }

        return new self($process);
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }
}
