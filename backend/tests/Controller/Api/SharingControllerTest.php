<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\SharingSettings;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\StoredUserSettings;
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

    private function storedSharing(string $email): SharingSettings
    {
        $this->entityManager()->clear();
        $user = $this->users()->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        return StoredUserSettings::sharing($this->entityManager(), $user);
    }

    public function testANewAccountSharesEverything(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'shares-by-default@example.test');

        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['enabled' => true, 'hiddenTargets' => [], 'rememberShares' => true],
            $this->sharing($client),
        );
    }

    public function testChangingSharingPersistsIt(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'quiet-sharer@example.test');

        $this->patchSharing($client, [
            'enabled' => false,
            'hiddenTargets' => ['facebook', 'x'],
            'rememberShares' => true,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([
            'enabled' => false,
            'hiddenTargets' => ['facebook', 'x'],
            'rememberShares' => true,
        ], $this->sharing($client));
        $stored = $this->storedSharing('quiet-sharer@example.test');
        self::assertFalse($stored->isEnabled());
        self::assertSame(['facebook', 'x'], $stored->getHiddenTargets());
    }

    public function testATargetListedTwiceIsStoredOnce(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'twice-hidden@example.test');

        $this->patchSharing($client, [
            'enabled' => true,
            'hiddenTargets' => ['x', 'reddit', 'x'],
            'rememberShares' => true,
        ]);

        self::assertResponseIsSuccessful();
        $stored = $this->storedSharing('twice-hidden@example.test');
        self::assertSame(['x', 'reddit'], $stored->getHiddenTargets());
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
        $stored = $this->storedSharing('future-target@example.test');
        self::assertSame(['mastodon-app'], $stored->getHiddenTargets());
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
                range(1, SharingSettings::MAX_HIDDEN_TARGETS + 1),
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
        $stored = $this->storedSharing('rejected-sharing@example.test');
        self::assertTrue($stored->isEnabled());
        self::assertSame([], $stored->getHiddenTargets());
    }

    public function testExactlyTheMaximumIsAccepted(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'many-hidden@example.test');
        $ids = array_map(
            static fn (int $index): string => 'target-' . $index,
            range(1, SharingSettings::MAX_HIDDEN_TARGETS),
        );

        $this->patchSharing($client, ['enabled' => true, 'hiddenTargets' => $ids, 'rememberShares' => true]);

        self::assertResponseIsSuccessful();
        self::assertSame($ids, $this->storedSharing('many-hidden@example.test')->getHiddenTargets());
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
        self::assertFalse($this->storedSharing('forgetful-sharer@example.test')->isRememberingShares());
    }

    public function testASignedOutClientIsRejected(): void
    {
        $client = static::createClient();

        $this->patchSharing($client, ['enabled' => false, 'hiddenTargets' => [], 'rememberShares' => true]);

        self::assertResponseStatusCodeSame(401);
    }
}
