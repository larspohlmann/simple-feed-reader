<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\LandingChallenge;
use PHPUnit\Framework\TestCase;

final class LandingChallengeTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function challengeBodies(): iterable
    {
        yield 'cloudflare verification' => ['<html><body class="cf-browser-verification">Just a moment…</body></html>'];
        yield 'cloudflare challenge platform' => ['<script src="/cdn-cgi/challenge-platform/h/b/orchestrate"></script>'];
        yield 'cloudflare challenge form' => ['<form id="challenge-form" action="/cdn-cgi/l/chk_jschl">'];
        yield 'anubis' => ['<script id="anubis_challenge" type="application/json">{}</script>'];
        yield 'siteground captcha' => ['<meta http-equiv="refresh" content="0;url=/.well-known/sgcaptcha/">'];
    }

    /**
     * @dataProvider challengeBodies
     */
    public function testRecognisesAChallengeBody(string $body): void
    {
        self::assertTrue((new LandingChallenge())->matches($body));
    }

    public function testDoesNotRejectAnArticleThatMerelyMentionsAChallengeInProse(): void
    {
        $body = '<html><body><h1>Are you a robot?</h1><p>Solving a captcha proves you are human.</p></body></html>';

        self::assertFalse((new LandingChallenge())->matches($body));
    }

    public function testDoesNotMatchAnEmptyBody(): void
    {
        self::assertFalse((new LandingChallenge())->matches(''));
    }
}
