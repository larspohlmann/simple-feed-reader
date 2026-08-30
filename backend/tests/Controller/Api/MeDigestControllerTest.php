<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The account's own write path onto its email-digest configuration (#636),
 * including the first-enable seeding of digestLastSentAt (spec Q5): the first
 * digest a newly-opted-in account receives must cover only entries that
 * arrive after opt-in, not everything the account has ever accumulated.
 */
final class MeDigestControllerTest extends ApiTestCase
{
    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }

    /** Attaches a bearer token to every subsequent request this client makes. */
    private function authenticate(KernelBrowser $client, string $email): void
    {
        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $manager->create($user));
    }

    public function testTheDigestConfigurationCanBeUpdated(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('digest-update@example.test');
        $this->authenticate($client, 'digest-update@example.test');

        $client->request(
            'PATCH',
            '/api/me/digest',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['enabled' => true, 'cadence' => 'weekly', 'sendHour' => 9, 'weekday' => 3, 'format' => 'html'],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseIsSuccessful();
        $preferences = $this->payload($client)['preferences'];
        self::assertIsArray($preferences);
        self::assertSame(
            [
                'enabled' => true,
                'cadence' => 'weekly',
                'format' => 'html',
                'sendHour' => 9,
                'weekday' => 3,
                'timezone' => 'UTC',
            ],
            $preferences['digest'],
        );

        $this->entityManager()->clear();
        $reloaded = $this->users()->find($user->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertTrue($reloaded->getPreferences()->isDigestEnabled());
    }

    public function testTheLowestAndHighestValidSendHoursAreAccepted(): void
    {
        $client = static::createClient();
        $this->factory()->create('digest-hour-boundary@example.test');
        $this->authenticate($client, 'digest-hour-boundary@example.test');

        $client->request(
            'PATCH',
            '/api/me/digest',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['enabled' => true, 'cadence' => 'daily', 'sendHour' => 0, 'weekday' => 1, 'format' => 'html'],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseIsSuccessful();

        $this->authenticate($client, 'digest-hour-boundary@example.test');
        $client->request(
            'PATCH',
            '/api/me/digest',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['enabled' => true, 'cadence' => 'daily', 'sendHour' => 23, 'weekday' => 1, 'format' => 'html'],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseIsSuccessful();
        $preferences = $this->payload($client)['preferences'];
        self::assertIsArray($preferences);
        $digest = $preferences['digest'];
        self::assertIsArray($digest);
        self::assertSame(23, $digest['sendHour']);
    }

    public function testASendHourBelowTheValidRangeIsRejected(): void
    {
        $client = static::createClient();
        $this->factory()->create('digest-hour-negative@example.test');
        $this->authenticate($client, 'digest-hour-negative@example.test');

        $client->request(
            'PATCH',
            '/api/me/digest',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['enabled' => true, 'cadence' => 'daily', 'sendHour' => -1, 'weekday' => 1, 'format' => 'html'],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }

    public function testAnOutOfRangeSendHourIsRejected(): void
    {
        $client = static::createClient();
        $this->factory()->create('digest-bad-hour@example.test');
        $this->authenticate($client, 'digest-bad-hour@example.test');

        $client->request(
            'PATCH',
            '/api/me/digest',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['enabled' => true, 'cadence' => 'weekly', 'sendHour' => 30, 'weekday' => 3, 'format' => 'html'],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }

    public function testTheHighestValidWeekdayIsAccepted(): void
    {
        $client = static::createClient();
        $this->factory()->create('digest-weekday-boundary@example.test');
        $this->authenticate($client, 'digest-weekday-boundary@example.test');

        $client->request(
            'PATCH',
            '/api/me/digest',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['enabled' => true, 'cadence' => 'weekly', 'sendHour' => 9, 'weekday' => 7, 'format' => 'html'],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseIsSuccessful();
        $preferences = $this->payload($client)['preferences'];
        self::assertIsArray($preferences);
        $digest = $preferences['digest'];
        self::assertIsArray($digest);
        self::assertSame(7, $digest['weekday']);
    }

    public function testAnOutOfRangeWeekdayIsRejected(): void
    {
        $client = static::createClient();
        $this->factory()->create('digest-bad-weekday@example.test');
        $this->authenticate($client, 'digest-bad-weekday@example.test');

        $client->request(
            'PATCH',
            '/api/me/digest',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['enabled' => true, 'cadence' => 'weekly', 'sendHour' => 9, 'weekday' => 8, 'format' => 'html'],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }

    public function testAWeekdayBelowTheValidRangeIsRejected(): void
    {
        $client = static::createClient();
        $this->factory()->create('digest-weekday-zero@example.test');
        $this->authenticate($client, 'digest-weekday-zero@example.test');

        $client->request(
            'PATCH',
            '/api/me/digest',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['enabled' => true, 'cadence' => 'weekly', 'sendHour' => 9, 'weekday' => 0, 'format' => 'html'],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }

    public function testAnUnknownCadenceIsRejected(): void
    {
        $client = static::createClient();
        $this->factory()->create('digest-bad-cadence@example.test');
        $this->authenticate($client, 'digest-bad-cadence@example.test');

        $client->request(
            'PATCH',
            '/api/me/digest',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['enabled' => true, 'cadence' => 'hourly', 'sendHour' => 9, 'weekday' => 3, 'format' => 'html'],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }

    public function testFirstEnableSeedsDigestLastSentAt(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('digest-first-enable@example.test');
        $this->authenticate($client, 'digest-first-enable@example.test');

        self::assertNull($user->getPreferences()->getDigestLastSentAt());

        $client->request(
            'PATCH',
            '/api/me/digest',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['enabled' => true, 'cadence' => 'daily', 'sendHour' => 8, 'weekday' => 1, 'format' => 'html'],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseIsSuccessful();

        $this->entityManager()->clear();
        $reloaded = $this->users()->find($user->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertNotNull($reloaded->getPreferences()->getDigestLastSentAt());
    }

    public function testASecondEnablingPatchDoesNotMoveDigestLastSentAt(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('digest-second-enable@example.test');
        $this->authenticate($client, 'digest-second-enable@example.test');

        $client->request(
            'PATCH',
            '/api/me/digest',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['enabled' => true, 'cadence' => 'daily', 'sendHour' => 8, 'weekday' => 1, 'format' => 'html'],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseIsSuccessful();

        $this->entityManager()->clear();
        $afterFirstEnable = $this->users()->find($user->getId());
        self::assertInstanceOf(User::class, $afterFirstEnable);
        $seededAt = $afterFirstEnable->getPreferences()->getDigestLastSentAt();
        self::assertNotNull($seededAt);

        $this->authenticate($client, 'digest-second-enable@example.test');
        $client->request(
            'PATCH',
            '/api/me/digest',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['enabled' => true, 'cadence' => 'weekly', 'sendHour' => 10, 'weekday' => 5, 'format' => 'html'],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseIsSuccessful();

        $this->entityManager()->clear();
        $afterSecondEnable = $this->users()->find($user->getId());
        self::assertInstanceOf(User::class, $afterSecondEnable);
        self::assertEquals($seededAt, $afterSecondEnable->getPreferences()->getDigestLastSentAt());
    }

    public function testDisablingThenReenablingDoesNotReseedDigestLastSentAt(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('digest-reenable@example.test');
        $this->authenticate($client, 'digest-reenable@example.test');

        $client->request(
            'PATCH',
            '/api/me/digest',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['enabled' => true, 'cadence' => 'daily', 'sendHour' => 8, 'weekday' => 1, 'format' => 'html'],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseIsSuccessful();

        $this->entityManager()->clear();
        $afterEnable = $this->users()->find($user->getId());
        self::assertInstanceOf(User::class, $afterEnable);
        $seededAt = $afterEnable->getPreferences()->getDigestLastSentAt();
        self::assertNotNull($seededAt);

        $this->authenticate($client, 'digest-reenable@example.test');
        $client->request(
            'PATCH',
            '/api/me/digest',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['enabled' => false, 'cadence' => 'daily', 'sendHour' => 8, 'weekday' => 1, 'format' => 'html'],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseIsSuccessful();

        $this->authenticate($client, 'digest-reenable@example.test');
        $client->request(
            'PATCH',
            '/api/me/digest',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['enabled' => true, 'cadence' => 'daily', 'sendHour' => 8, 'weekday' => 1, 'format' => 'html'],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseIsSuccessful();

        $this->entityManager()->clear();
        $afterReenable = $this->users()->find($user->getId());
        self::assertInstanceOf(User::class, $afterReenable);
        self::assertEquals($seededAt, $afterReenable->getPreferences()->getDigestLastSentAt());
    }

    public function testUpdatingTheDigestRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request(
            'PATCH',
            '/api/me/digest',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['enabled' => true, 'cadence' => 'daily', 'sendHour' => 8, 'weekday' => 1, 'format' => 'html'],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseStatusCodeSame(401);
    }
}
