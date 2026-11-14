<?php

declare(strict_types=1);

namespace App\Service\Discovery;

/**
 * Recognises a bot gate's challenge page, which arrives with a success status (SiteGround answers 202). Kept narrow:
 * a false positive refuses a subscription that would work, so the page must carry both the captcha path and the
 * refresh to it. Add another vendor only once its markup has been observed.
 */
final readonly class BotChallengePage
{
    /** Where SiteGround's gate sends the browser to prove it is one. */
    private const string CAPTCHA_PATH = '/.well-known/sgcaptcha/';

    private const string META_REFRESH = 'http-equiv="refresh"';

    public function wasReturned(string $body): bool
    {
        return str_contains($body, self::CAPTCHA_PATH)
            && str_contains($body, self::META_REFRESH);
    }
}
