<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Preferences;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class SharingControllerTest extends ApiTestCase
{
    /** @param array<string, mixed> $body */
    private function patchSharing(KernelBrowser $client, array $body): void
    {
        $client->request(
            'PATCH',
            '/api/me/sharing',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    private function sharing(KernelBrowser $client): mixed
    {
        $preferences = $this->payload($client)['preferences'];
        self::assertIsArray($preferences);

        return $preferences['sharing'];
    }

    private function storedPreferences(string $email): Preferences
    {
        $this->entityManager()->clear();
        $user = $this->users()->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        return $user->getPreferences();
    }

    public function testANewAccountSharesEverything(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'shares-by-default@example.test');

        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertSame(['enabled' => true, 'hiddenTargets' => [], 'rememberShares' => true], $this->sharing($client));
    }

    public function testChangingSharingPersistsIt(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'quiet-sharer@example.test');

        $this->patchSharing($client, [
                'enabled' => false,
                'hiddenTargets' => ['facebook',
                'x'],
                'rememberShares' => true,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([
                'enabled' => false,
                'hiddenTargets' => ['facebook',
                'x'],
                'rememberShares' => true,
        ], $this->sharing($client));
        $stored = $this->storedPreferences('quiet-sharer@example.test');
        self::assertFalse($stored->isSharingEnabled());
        self::assertSame(['facebook', 'x'], $stored->getHiddenShareTargets());
    }

    public function testATargetListedTwiceIsStoredOnce(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'twice-hidden@example.test');

        $this->patchSharing($client, [
                'enabled' => true,
                'hiddenTargets' => ['x',
                'reddit',
                'x'],
                'rememberShares' => true,
        ]);

        self::assertResponseIsSuccessful();
        $stored = $this->storedPreferences('twice-hidden@example.test');
        self::assertSame(['x', 'reddit'], $stored->getHiddenShareTargets());
    }

    public function testAnUnknownButWellFormedIdIsKept(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'future-target@example.test');

        $this->patchSharing($client, [
                'enabled' => true,
                'hiddenTargets' => ['mastodon-app'],
                'rememberShares' => true,
        ]);

        self::assertResponseIsSuccessful();
        $stored = $this->storedPreferences('future-target@example.test');
        self::assertSame(['mastodon-app'], $stored->getHiddenShareTargets());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidBodies(): iterable
    {
        yield 'empty body' => [[]];
        yield 'no list' => [['enabled' => false, 'rememberShares' => true]];
        yield 'no flag' => [['hiddenTargets' => [], 'rememberShares' => true]];
        yield 'no remember flag' => [['enabled' => true, 'hiddenTargets' => []]];
        yield 'upper case id' => [['enabled' => true, 'hiddenTargets' => ['Facebook'], 'rememberShares' => true]];
        yield 'id with a space' => [['enabled' => true, 'hiddenTargets' => ['face book'], 'rememberShares' => true]];
        yield 'empty id' => [['enabled' => true, 'hiddenTargets' => [''], 'rememberShares' => true]];
        yield 'id over 32 characters' => [[
            'enabled' => true,
            'hiddenTargets' => [str_repeat('a', 33)],
            'rememberShares' => true,
        ]];
        yield 'non-string id' => [['enabled' => true, 'hiddenTargets' => [['x']], 'rememberShares' => true]];
        yield 'too many ids' => [[
            'enabled' => true,
            'rememberShares' => true,
            'hiddenTargets' => array_map(
                static fn (int $index): string => 'target-' . $index,
                range(1, Preferences::MAX_HIDDEN_SHARE_TARGETS + 1),
            ),
        ]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidBodies')]
    public function testAnInvalidBodyIsRejectedAndChangesNothing(array $body): void
    {
        $client = static::createClient();
        $this->signIn($client, 'rejected-sharing@example.test');

        $this->patchSharing($client, $body);

        self::assertResponseStatusCodeSame(422);
        $stored = $this->storedPreferences('rejected-sharing@example.test');
        self::assertTrue($stored->isSharingEnabled());
        self::assertSame([], $stored->getHiddenShareTargets());
    }

    public function testExactlyTheMaximumIsAccepted(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'many-hidden@example.test');
        $ids = array_map(
            static fn (int $index): string => 'target-' . $index,
            range(1, Preferences::MAX_HIDDEN_SHARE_TARGETS),
        );

        $this->patchSharing($client, ['enabled' => true, 'hiddenTargets' => $ids, 'rememberShares' => true]);

        self::assertResponseIsSuccessful();
        self::assertSame($ids, $this->storedPreferences('many-hidden@example.test')->getHiddenShareTargets());
    }

    public function testRememberSharesIsStoredAndEchoed(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'forgetful-sharer@example.test');

        $this->patchSharing($client, ['enabled' => true, 'hiddenTargets' => [], 'rememberShares' => false]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['enabled' => true, 'hiddenTargets' => [], 'rememberShares' => false],
            $this->sharing($client),
        );
        self::assertFalse($this->storedPreferences('forgetful-sharer@example.test')->isRememberingShares());
    }

    public function testASignedOutClientIsRejected(): void
    {
        $client = static::createClient();

        $this->patchSharing($client, ['enabled' => false, 'hiddenTargets' => [], 'rememberShares' => true]);

        self::assertResponseStatusCodeSame(401);
    }
}
