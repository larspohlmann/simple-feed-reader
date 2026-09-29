<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Auth\AltchaService;

/**
 * Builds the payload the widget would submit by brute-forcing an issued challenge: verify() checks an HMAC and a
 * preimage, so only a solved payload passes. About 60 ms per call.
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
