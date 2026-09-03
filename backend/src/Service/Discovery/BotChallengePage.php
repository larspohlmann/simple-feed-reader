<?php

declare(strict_types=1);

namespace App\Service\Discovery;

/**
 * Recognises the page a bot gate serves in place of the site.
 *
 * Such a gate refuses with a success status — SiteGround answers 202 — so
 * nothing in the response line distinguishes "you may not have this" from
 * "here is your page"; only the body does, and telling them apart matters:
 * otherwise a user is told an address holds no feed while it serves one to
 * every client the gate trusts (#424).
 *
 * Recognition stays narrow: a page merely naming the path is still a page,
 * and a false positive costs a subscription refused for a site that would
 * have worked — so a challenge only counts when it also carries the redirect
 * to the captcha. Other vendors get their own case once observed; guessing
 * their markup would trade this issue for the opposite one.
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
