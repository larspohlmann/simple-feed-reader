<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * Recognises a bot- or consent-gate interstitial served with a 2xx status in
 * place of the article. Every marker is a vendor/machine string, never prose, so
 * the pre-extraction check cannot reject an article that merely names a captcha.
 * A new vendor earns a row once its markup is observed (cf. BotChallengePage, #424).
 */
final readonly class LandingChallenge
{
    private const array MARKERS = [
        'cf-browser-verification',
        '/cdn-cgi/challenge-platform/',
        'id="challenge-form"',
        'anubis_challenge',
        '/.well-known/sgcaptcha/',
    ];

    public function matches(string $html): bool
    {
        foreach (self::MARKERS as $marker) {
            if (str_contains($html, $marker)) {
                return true;
            }
        }

        return false;
    }
}
