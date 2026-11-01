<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

final readonly class ApiRequestModel
{
    /**
     * @param array<string, string>     $headers
     * @param array<string, mixed>|null $json
     * @param string|null               $body raw bytes, never set together with $json
     */
    private function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public ?array $json,
        public ?string $body,
    ) {
    }

    /** @param array<string, string> $headers */
    public static function get(string $url, array $headers = []): self
    {
        return new self('GET', $url, $headers, null, null);
    }

    /** @param array<string, string> $headers */
    public static function post(string $url, array $headers = []): self
    {
        return new self('POST', $url, $headers, null, null);
    }

    /** @param array<string, string> $headers */
    public static function delete(string $url, array $headers = []): self
    {
        return new self('DELETE', $url, $headers, null, null);
    }

    /**
     * @param array<string, mixed>  $json
     * @param array<string, string> $headers
     */
    public static function postJson(string $url, array $json, array $headers = []): self
    {
        return new self('POST', $url, $headers, $json, null);
    }

    public static function postBytes(string $url, string $bytes, string $contentType): self
    {
        return new self('POST', $url, ['Content-Type' => $contentType], null, $bytes);
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->method, $this->url, [...$this->headers, ...$headers], $this->json, $this->body);
    }
}
