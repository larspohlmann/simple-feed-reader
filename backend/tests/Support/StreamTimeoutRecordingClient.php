<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/** Delegates to another client and records the idle timeout of every stream it was asked for. */
final class StreamTimeoutRecordingClient implements HttpClientInterface
{
    use DecoratorTrait;

    /** @var list<float|null> */
    public array $streamTimeouts = [];

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $this->streamTimeouts[] = $timeout;

        return $this->client->stream($responses, $timeout);
    }
}
