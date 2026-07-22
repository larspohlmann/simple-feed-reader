<?php

declare(strict_types=1);

namespace App\Tests\E2e\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Base for black-box e2e tests. Talks only HTTP to the running stack — boots no
 * kernel, touches no container service. Everything a test needs (the app
 * client, Mailpit, the ALTCHA solver, unique addresses) hangs off here.
 */
abstract class E2eTestCase extends TestCase
{
    protected HttpClientInterface $http;
    protected MailpitClient $mailpit;
    protected HttpAltchaSolver $altcha;
    protected string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = $this->env('E2E_BASE_URL', 'https://localhost:8443');
        $this->http = HttpClient::createForBaseUri($this->baseUrl);
        $this->mailpit = new MailpitClient($this->env('E2E_MAILPIT_URL', 'http://localhost:8025'));
        $this->altcha = new HttpAltchaSolver($this->http, $this->baseUrl);
    }

    private function env(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /** A fresh address per call so runs never collide and no DB reset is needed. */
    protected function uniqueEmail(string $prefix = 'e2e'): string
    {
        return \sprintf('%s-%s@example.com', $prefix, bin2hex(random_bytes(8)));
    }

    /**
     * POST JSON and return the response without throwing on 4xx/5xx.
     *
     * @param array<string, mixed> $body
     */
    protected function postJson(string $path, array $body, ?string $bearer = null): ResponseInterface
    {
        $options = ['json' => $body];
        if (null !== $bearer) {
            $options['headers'] = ['Authorization' => 'Bearer ' . $bearer];
        }

        return $this->http->request('POST', $path, $options);
    }

    protected function getJson(string $path, ?string $bearer = null): ResponseInterface
    {
        $options = [];
        if (null !== $bearer) {
            $options['headers'] = ['Authorization' => 'Bearer ' . $bearer];
        }

        return $this->http->request('GET', $path, $options);
    }

    /** Register a fresh account through the real ALTCHA-gated endpoint. */
    protected function register(string $email, string $password): ResponseInterface
    {
        return $this->postJson('/api/auth/register', [
            'email' => $email,
            'password' => $password,
            'altcha' => $this->altcha->solve(),
        ]);
    }

    /** Pull the `token` query param out of the first link in an email body. */
    protected function tokenFromEmail(string $recipient): string
    {
        $body = $this->mailpit->latestBodyTo($recipient);

        if (1 !== preg_match('/[?&]token=([A-Za-z0-9._\-]+)/', $body, $m)) {
            throw new \RuntimeException('No token link found in email to ' . $recipient);
        }

        return $m[1];
    }

    /** Log in and return the JWT. */
    protected function login(string $email, string $password): string
    {
        $response = $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
        self::assertSame(200, $response->getStatusCode(), 'login should succeed');

        $token = $response->toArray()['token'] ?? null;

        if (!is_string($token)) {
            throw new \RuntimeException('Login response did not contain a string token.');
        }

        return $token;
    }
}
