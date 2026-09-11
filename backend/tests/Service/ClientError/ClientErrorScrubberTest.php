<?php

declare(strict_types=1);

namespace App\Tests\Service\ClientError;

use App\Dto\ClientError\ClientErrorItem;
use App\Service\ClientError\ClientErrorScrubber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientErrorScrubberTest extends TestCase
{
    private ClientErrorScrubber $scrubber;

    protected function setUp(): void
    {
        $this->scrubber = new ClientErrorScrubber();
    }

    public function testStripsQueryAndFragmentFromUrl(): void
    {
        $scrubbed = $this->scrubber->scrub($this->item(url: 'https://app.example/reader?token=abc123#/entry/9'));

        self::assertSame('https://app.example/reader', $scrubbed->url);
    }

    public function testLeavesAUrlWithNoPathUnchanged(): void
    {
        $scrubbed = $this->scrubber->scrub($this->item(url: 'https://app.example'));

        self::assertSame('https://app.example', $scrubbed->url);
    }

    public function testStripsAQueryFromAPathLessUrl(): void
    {
        $scrubbed = $this->scrubber->scrub($this->item(url: 'https://app.example?token=SECRETVALUE'));

        self::assertSame('https://app.example', $scrubbed->url);
    }

    public function testStripsQueryAndFragmentFromAPathLessUrlWithMultipleSecrets(): void
    {
        $scrubbed = $this->scrubber->scrub($this->item(url: 'https://app.example?token=A&password=B#frag'));

        self::assertSame('https://app.example', $scrubbed->url);
    }

    public function testStripsTheQueryFromAPurelyRelativeUrl(): void
    {
        $scrubbed = $this->scrubber->scrub($this->item(url: '?token=SECRETVALUE'));

        self::assertStringNotContainsString('SECRETVALUE', (string) $scrubbed->url);
    }

    public function testRedactsBearerTokensInStack(): void
    {
        $scrubbed = $this->scrubber->scrub($this->item(
            stack: 'at fetch (Authorization: Bearer eyJ0.aaa.bbb) line 3',
        ));

        self::assertStringNotContainsString('eyJ0.aaa.bbb', (string) $scrubbed->stack);
        self::assertStringContainsString('[REDACTED]', (string) $scrubbed->stack);
    }

    public function testRedactsEmailAddressesInMessage(): void
    {
        $scrubbed = $this->scrubber->scrub($this->item(message: 'save failed for jane.doe@example.com today'));

        self::assertStringNotContainsString('jane.doe@example.com', $scrubbed->message);
        self::assertStringContainsString('[REDACTED_EMAIL]', $scrubbed->message);
    }

    public function testRedactsQueryValuesEmbeddedInMessages(): void
    {
        $scrubbed = $this->scrubber->scrub($this->item(message: 'GET /api/x?apiKey=SECRETVALUE&page=2 failed'));

        self::assertStringNotContainsString('SECRETVALUE', $scrubbed->message);
        self::assertStringContainsString('page=2', $scrubbed->message, 'a low-risk key stays readable');
    }

    public function testRedactsTokenQueryValueInMessage(): void
    {
        $scrubbed = $this->scrubber->scrub($this->item(message: 'GET /api/x?token=SECRETVALUE&page=2 failed'));

        self::assertStringNotContainsString('SECRETVALUE', $scrubbed->message);
        self::assertStringContainsString('?token=[REDACTED]', $scrubbed->message);
    }

    public function testRedactsAStandaloneJwtInStack(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';

        $scrubbed = $this->scrubber->scrub($this->item(stack: "token payload $jwt leaked"));

        self::assertStringNotContainsString($jwt, (string) $scrubbed->stack);
        self::assertStringContainsString('[REDACTED_JWT]', (string) $scrubbed->stack);
    }

    public function testRedactsALongHexRunInStack(): void
    {
        $hex = '5f4dcc3b5aa765d61d8327deb882cf99aabbccddeeff00112233445566778899';

        $scrubbed = $this->scrubber->scrub($this->item(stack: "session id $hex leaked"));

        self::assertStringNotContainsString($hex, (string) $scrubbed->stack);
        self::assertStringContainsString('[REDACTED_HEX]', (string) $scrubbed->stack);
    }

    public function testStripsQueryAndFragmentFromRoute(): void
    {
        $scrubbed = $this->scrubber->scrub($this->item(route: '/reader/entry/9?token=SECRET#frag'));

        self::assertSame('/reader/entry/9', $scrubbed->route);
    }

    public function testLeavesACleanRouteUnchanged(): void
    {
        $scrubbed = $this->scrubber->scrub($this->item(route: '/reader/inbox'));

        self::assertSame('/reader/inbox', $scrubbed->route);
    }

    public function testLeavesANullRouteUnchanged(): void
    {
        $scrubbed = $this->scrubber->scrub($this->item(route: null));

        self::assertNull($scrubbed->route);
    }

    public function testLeavesAPlainMessageUntouched(): void
    {
        $scrubbed = $this->scrubber->scrub($this->item(message: 'Cannot read properties of undefined'));

        self::assertSame('Cannot read properties of undefined', $scrubbed->message);
    }

    #[DataProvider('plainStackProvider')]
    public function testLeavesAPlainStackByteForByteUnchanged(string $stack): void
    {
        $scrubbed = $this->scrubber->scrub($this->item(stack: $stack));

        self::assertSame($stack, $scrubbed->stack);
    }

    /** @return iterable<string, array{string}> */
    public static function plainStackProvider(): iterable
    {
        yield 'short trace' => ['at render (app.js:42:7)'];
        yield 'multi-line trace' => [
            "TypeError: x is undefined\n    at render (app.js:42:7)\n    at boot (main.js:3:1)",
        ];
    }

    private function item(
        string $message = 'boom',
        ?string $stack = null,
        ?string $url = null,
        ?string $route = '/reader',
    ): ClientErrorItem {
        return new ClientErrorItem(
            message: $message,
            stack: $stack,
            kind: 'Error',
            url: $url,
            route: $route,
            buildVersion: 'dev+local@',
            userAgent: 'jest',
            at: '2026-09-11T00:00:00.000Z',
        );
    }
}
