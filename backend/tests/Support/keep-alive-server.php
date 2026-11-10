<?php

declare(strict_types=1);

// Usage: php keep-alive-server.php <ip> <port> <name>. Answers every request with "<name>#<connection number>".
$arguments = $_SERVER['argv'] ?? [];
if (!is_array($arguments) || 4 !== count($arguments)) {
    fwrite(STDERR, "usage: keep-alive-server.php <ip> <port> <name>\n");
    exit(2);
}
$asText = static fn (mixed $argument): string => is_scalar($argument) ? (string) $argument : '';
[, $ip, $port, $name] = array_map($asText, $arguments);
$server = stream_socket_server("tcp://$ip:$port", $errorCode, $errorMessage);
if (false === $server) {
    fwrite(STDERR, "$errorMessage\n");
    exit(1);
}
fwrite(STDOUT, "ready\n");
$connections = [];
$connectionCount = 0;
$readable = [$server];
$unused = null;
while (false !== stream_select($readable, $unused, $unused, null)) {
    foreach ($readable as $socket) {
        if ($socket === $server) {
            $accepted = stream_socket_accept($server);
            if (false !== $accepted) {
                $connections[++$connectionCount] = $accepted;
            }
            continue;
        }
        $number = array_search($socket, $connections, true);
        $request = fread($socket, 65536);
        if ('' === $request || false === $request) {
            fclose($socket);
            unset($connections[$number]);
            continue;
        }
        $body = "$name#$number";
        $headers = 'Content-Length: ' . strlen($body) . "\r\nConnection: keep-alive";
        fwrite($socket, "HTTP/1.1 200 OK\r\n$headers\r\n\r\n$body");
    }
    $readable = [$server, ...array_values($connections)];
}
