<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Auth\AltchaChallenge;

/** The JSON the ALTCHA browser widget consumes verbatim. */
final class AltchaChallengeJson
{
    /** @return array{algorithm: string, challenge: string, salt: string, signature: string, maxnumber: int} */
    public static function from(AltchaChallenge $challenge): array
    {
        return [
            'algorithm' => $challenge->algorithm,
            'challenge' => $challenge->challenge,
            'salt' => $challenge->salt,
            'signature' => $challenge->signature,
            // The widget's field name is lowercase.
            'maxnumber' => $challenge->maxNumber,
        ];
    }
}
