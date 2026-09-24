<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The claim asserted through the REAL password-login path, never by calling
 * the listener directly — same rule LastLoginStampTest holds its sibling
 * listener to. AddUserIdClaimOnTokenIssueTest already proves the dispatcher
 * reaches the listener; this proves the wired-up json_login handler actually
 * carries the claim into the token a client receives. The OAuth and passkey
 * paths are covered where their own flows already run end to end:
 * OAuthFlowTest::testTheHappyPathTakesAnActiveUserFromRedirectToJwt and
 * PasskeyLoginTest::testTheTokenClaimsMatchPasswordLoginForTheSameUser.
 */
final class UserIdClaimTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function factory(): UserFactory
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($em, $hasher);
    }

    public function testAPasswordLoginTokenCarriesTheAccountId(): void
    {
        $this->factory()->create('claim-user-one@example.com');
        $second = $this->factory()->create('claim-user-two@example.com');

        $this->client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'email' => 'claim-user-two@example.com',
                'password' => 'correct-horse-battery',
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['token']);

        /** @var JWTTokenManagerInterface $tokens */
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $claims = $tokens->parse($payload['token']);

        self::assertSame($second->getId(), $claims['userId'] ?? null);
    }
}
