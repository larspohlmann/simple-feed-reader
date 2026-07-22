<?php

declare(strict_types=1);

namespace App\Tests\E2e\Support;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Solves a real ALTCHA challenge over HTTP — the same brute-force the browser
 * widget does. There is no shortcut: the server checks an HMAC signature and a
 * hash preimage, so only a genuinely solved payload passes verification.
 */
final class HttpAltchaSolver
{
    public function __construct(private readonly HttpClientInterface $http, private readonly string $baseUrl)
    {
    }

    public function solve(): string
    {
        $challenge = $this->http->request('GET', $this->baseUrl . '/api/auth/altcha-challenge')->toArray();

        $algorithm = $challenge['algorithm'] ?? null;
        $challengeHash = $challenge['challenge'] ?? null;
        $salt = $challenge['salt'] ?? null;
        $signature = $challenge['signature'] ?? null;
        $maxNumber = $challenge['maxnumber'] ?? null;

        if (
            !is_string($algorithm)
            || !is_string($challengeHash)
            || !is_string($salt)
            || !is_string($signature)
            || !is_int($maxNumber)
        ) {
            throw new \RuntimeException('ALTCHA challenge response was missing expected fields.');
        }

        for ($number = 0; $number <= $maxNumber; ++$number) {
            if (hash('sha256', $salt . $number) === $challengeHash) {
                return base64_encode((string) json_encode([
                    'algorithm' => $algorithm,
                    'challenge' => $challengeHash,
                    'number' => $number,
                    'salt' => $salt,
                    'signature' => $signature,
                ]));
            }
        }

        throw new \RuntimeException('ALTCHA challenge was not solvable within maxnumber.');
    }
}
