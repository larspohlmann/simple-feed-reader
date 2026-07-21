<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Auth\AltchaService;

/**
 * Builds the payload the browser widget would submit, by actually brute-forcing
 * a challenge the service issued. There is no shortcut: verify() checks an HMAC
 * we cannot forge and a hash preimage we cannot fake, so a solved payload is
 * the only kind that gets past it.
 *
 * Cost is ~60 ms per call at the configured difficulty. That is the
 * proof-of-work doing its job, not a hung test.
 */
final class AltchaSolver
{
    public static function solve(AltchaService $altcha): string
    {
        $challenge = $altcha->createChallenge();

        for ($number = 0; $number <= $challenge->maxNumber; ++$number) {
            if (hash('sha256', $challenge->salt . $number) === $challenge->challenge) {
                return base64_encode((string) json_encode([
                    'algorithm' => $challenge->algorithm,
                    'challenge' => $challenge->challenge,
                    'number' => $number,
                    'salt' => $challenge->salt,
                    'signature' => $challenge->signature,
                ]));
            }
        }

        throw new \RuntimeException('ALTCHA challenge was not solvable within maxnumber.');
    }
}
