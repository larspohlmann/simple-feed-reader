<?php

declare(strict_types=1);

namespace App\Tests\Support;

final class KeepAliveServer
{
    /** @param resource $process */
    private function __construct(private $process)
    {
    }

    /** @throws KeepAliveServerUnavailableException when the process cannot start or cannot bind the address */
    public static function start(string $ip, int $port, string $name): self
    {
        $host = str_contains($ip, ':') ? "[$ip]" : $ip;
        $process = proc_open(
            [\PHP_BINARY, __DIR__ . '/keep-alive-server.php', $host, (string) $port, $name],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!\is_resource($process)) {
            throw new KeepAliveServerUnavailableException('cannot start the keep-alive server process');
        }
        if ("ready\n" !== fgets($pipes[1])) {
            $reason = trim((string) stream_get_contents($pipes[2]));
            proc_terminate($process);
            proc_close($process);

            throw new KeepAliveServerUnavailableException("cannot serve on $ip:$port: $reason");
        }

        return new self($process);
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }
}
