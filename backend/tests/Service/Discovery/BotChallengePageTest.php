<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Service\Discovery\BotChallengePage;
use PHPUnit\Framework\TestCase;

final class BotChallengePageTest extends TestCase
{
    /**
     * The body SiteGround's gate returned for decodedmagazine.com,
     * datatransmission.co and deephouseamsterdam.com when the Strato box asked
     * for their feeds, kept byte for byte apart from the address (#424).
     */
    private function siteGroundChallenge(): string
    {
        // @lang TEXT: the challenge is the input under test and stays exactly as
        // the gate served it, rather than growing a `lang` attribute.
        return /** @lang TEXT */ '<html><head><link rel="icon" href="data:;">'
            . '<meta http-equiv="refresh" content="0;/.well-known/sgcaptcha/'
            . '?r=%2Ffeed%2F&y=ipc:81.169.144.135:1786832352.672"></meta></head></html>';
    }

    public function testRecognizesTheSiteGroundChallenge(): void
    {
        self::assertTrue((new BotChallengePage())->wasReturned($this->siteGroundChallenge()));
    }

    public function testIgnoresAnOrdinaryPage(): void
    {
        // @lang TEXT: a plain page stands in for every site that is merely
        // feedless, and must never be reported as a refusal.
        $page = /** @lang TEXT */ '<!doctype html><html><head><title>Blog</title></head>'
            . '<body><h1>Posts</h1></body></html>';

        self::assertFalse((new BotChallengePage())->wasReturned($page));
    }

    /**
     * An article about bot gates may name the path in its prose, and a page that
     * merely mentions it is not a page that refuses us — the redirect has to be
     * there too.
     */
    public function testIgnoresAPageThatOnlyMentionsTheCaptchaPath(): void
    {
        // @lang TEXT
        $page = /** @lang TEXT */ '<!doctype html><html><body><p>SiteGround serves '
            . '/.well-known/sgcaptcha/ to clients it distrusts.</p></body></html>';

        self::assertFalse((new BotChallengePage())->wasReturned($page));
    }

    public function testIgnoresARedirectThatIsNotAChallenge(): void
    {
        // @lang TEXT
        $page = /** @lang TEXT */ '<html><head><meta http-equiv="refresh" '
            . 'content="0;/new-home/"></head></html>';

        self::assertFalse((new BotChallengePage())->wasReturned($page));
    }

    public function testIgnoresAFeed(): void
    {
        $feed = '<?xml version="1.0"?><rss version="2.0"><channel><title>x</title>'
            . '<link>https://example.com</link></channel></rss>';

        self::assertFalse((new BotChallengePage())->wasReturned($feed));
    }
}
