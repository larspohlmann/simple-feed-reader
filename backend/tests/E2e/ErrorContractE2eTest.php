<?php

declare(strict_types=1);

namespace App\Tests\E2e;

use App\Tests\E2e\Support\E2eTestCase;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The API's failure modes are part of its contract and must hold through nginx,
 * not just in the kernel. Two guards: an unsolved ALTCHA is a 422 problem+json
 * that creates no account, and an unauthenticated call is a 401 problem+json.
 */
final class ErrorContractE2eTest extends E2eTestCase
{
    public function testUnsolvedAltchaIsRejectedAsProblemJson(): void
    {
        $email = $this->uniqueEmail();

        $response = $this->postJson('/api/auth/register', [
            'email' => $email,
            'password' => 'valid-enough-password',
            'altcha' => 'garbage',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            'application/problem+json',
            $this->firstHeader($response, 'content-type'),
        );

        // errors.altcha must be present (narrow the mixed body for PHPStan max).
        $body = $response->toArray(false);
        self::assertArrayHasKey('errors', $body);
        self::assertIsArray($body['errors']);
        self::assertArrayHasKey('altcha', $body['errors']);

        // And it created no USABLE account: a solved registration for the same
        // address still reaches 202 rather than being rejected.
        self::assertSame(202, $this->register($email, 'valid-enough-password')->getStatusCode());
    }

    public function testUnauthenticatedMeIsProblemJson401(): void
    {
        $response = $this->getJson('/api/me');

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString(
            'application/problem+json',
            $this->firstHeader($response, 'content-type'),
        );
    }

    /**
     * getHeaders(false) is precisely typed as array<string, list<string>>, but
     * a missing header would still make `[0]` undefined; guard both steps so
     * PHPStan sees a real string rather than trusting an assumed offset.
     */
    private function firstHeader(ResponseInterface $response, string $name): string
    {
        $values = $response->getHeaders(false)[$name] ?? null;

        self::assertIsArray($values, \sprintf('Expected a "%s" header on the response.', $name));
        self::assertNotEmpty($values, \sprintf('Expected a "%s" header on the response.', $name));

        return $values[0];
    }
}
