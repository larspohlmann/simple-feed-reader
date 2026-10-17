<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\HiddenProfiles;

use App\Service\Bluesky\AppViewClient;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\HostThrottle;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfiles\AppViewHiddenProfiles;
use App\Service\Social\Model\HiddenProfileModel;
use App\Tests\Support\StubFeedFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class AppViewHiddenProfilesTest extends TestCase
{
    private const string GET_PROFILES = 'https://public.api.bsky.app/xrpc/app.bsky.actor.getProfiles';
    private const string RUSTY = 'did:plc:hczfd45prvqtdrw77256syag';

    private StubFeedFetcher $fetcher;

    protected function setUp(): void
    {
        $this->fetcher = new StubFeedFetcher();
    }

    public function testAHiddenProfileIsFoundByItsDidAndItsHandle(): void
    {
        $this->answer(['rusty-17.bsky.social'], [self::rusty(['displayName' => 'Rusty'])]);

        $hidden = $this->hiddenProfiles()->hiddenAmong(['rusty-17.bsky.social']);

        $expected = new HiddenProfileModel(self::RUSTY, 'Rusty');
        self::assertEquals($expected, $hidden['rusty-17.bsky.social'] ?? null);
        self::assertEquals($expected, $hidden[self::RUSTY] ?? null);
    }

    public function testAPublicProfileIsNotAmongTheHidden(): void
    {
        $this->answer(['did:plc:bob'], [['did' => 'did:plc:bob', 'handle' => 'bob.bsky.social']]);

        self::assertSame([], $this->hiddenProfiles()->hiddenAmong(['did:plc:bob']));
    }

    public function testAHiddenProfileWithoutADisplayNameIsNamedByItsHandle(): void
    {
        $this->answer([self::RUSTY], [self::rusty()]);

        $hidden = $this->hiddenProfiles()->hiddenAmong([self::RUSTY]);

        self::assertSame('Rusty-17.bsky.social', $hidden[self::RUSTY]->name);
    }

    public function testActorsAreAskedAPageAtATime(): void
    {
        $actors = array_map(static fn (int $index): string => sprintf('user%02d.bsky.social', $index), range(1, 26));
        $this->answer(\array_slice($actors, 0, 25), []);
        $this->answer(\array_slice($actors, 25), []);

        $this->hiddenProfiles()->hiddenAmong($actors);

        self::assertCount(2, $this->fetcher->fetchedUrls);
    }

    public function testNoActorsAskNothing(): void
    {
        self::assertSame([], $this->hiddenProfiles()->hiddenAmong([]));
        self::assertSame([], $this->fetcher->fetchedUrls);
    }

    public function testAnUnreachableAppViewIsAnUnreachableNetwork(): void
    {
        $this->fetcher->willThrow(self::profilesUrl([self::RUSTY]), new FeedUnreachableException('timeout'));

        $this->expectException(SocialNetworkUnreachableException::class);
        $this->hiddenProfiles()->hiddenAmong([self::RUSTY]);
    }

    public function testAMalformedAnswerIsAnUnreachableNetwork(): void
    {
        $this->fetcher->willReturnBody(self::profilesUrl([self::RUSTY]), '{"posts":[]}');

        $this->expectException(SocialNetworkUnreachableException::class);
        $this->hiddenProfiles()->hiddenAmong([self::RUSTY]);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, mixed>
     */
    private static function rusty(array $overrides = []): array
    {
        return $overrides + [
            'did' => self::RUSTY,
            'handle' => 'Rusty-17.bsky.social',
            'labels' => [[
                'src' => self::RUSTY,
                'uri' => 'at://' . self::RUSTY . '/app.bsky.actor.profile/self',
                'val' => '!no-unauthenticated',
            ]],
        ];
    }

    /**
     * @param list<string>               $actors
     * @param list<array<string, mixed>> $profiles
     */
    private function answer(array $actors, array $profiles): void
    {
        $this->fetcher->willReturnBody(
            self::profilesUrl($actors),
            json_encode(['profiles' => $profiles], \JSON_THROW_ON_ERROR),
        );
    }

    /** @param list<string> $actors */
    private static function profilesUrl(array $actors): string
    {
        $pairs = array_map(static fn (string $actor): string => 'actors=' . rawurlencode($actor), $actors);

        return self::GET_PROFILES . '?' . implode('&', $pairs);
    }

    private function hiddenProfiles(): AppViewHiddenProfiles
    {
        $clock = new MockClock('2026-10-17 12:00:00', 'UTC');

        return new AppViewHiddenProfiles(
            new AppViewClient($this->fetcher, new HostThrottle(new ArrayAdapter(clock: $clock), $clock)),
        );
    }
}
