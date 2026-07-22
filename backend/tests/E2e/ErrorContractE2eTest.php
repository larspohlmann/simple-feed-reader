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

        // And it created no account. Asserting "a later registration for the same
        // address still 202s" would prove nothing — /api/auth/register returns 202
        // for an already-existing address too, by enumeration-safe design. So prove
        // it through the one observable side effect of real creation: the
        // verification email. Registration only mails on genuine creation, and this
        // request was refused at the ALTCHA gate before that path.
        //
        // The mailer flushes on kernel.terminate (after the response), so a bare
        // "no mail yet" check could pass simply because a hypothetical mail had not
        // flushed. Register a *control* account and block until ITS mail lands —
        // that proves the mailer has since flushed — then assert the rejected
        // address still has no mail at all.
        $control = $this->uniqueEmail();
        self::assertSame(202, $this->register($control, 'valid-enough-password')->getStatusCode());
        $this->mailpit->latestBodyTo($control);

        self::assertFalse(
            $this->mailpit->hasMessageTo($email),
            'A registration rejected for a bad ALTCHA must create no account and send no verification mail.',
        );
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
