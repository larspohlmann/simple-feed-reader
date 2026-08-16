<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The read side of the run cost history (#409): the account's newest runs
 * with what each one cost, and the all-time total banked over every run it
 * ever made. Read-only, so ownership is proven the same way the #309 debug
 * log test proves it — a second account's run must not leak in, either into
 * the list or into the total.
 */
final class RecommendationRunHistoryControllerTest extends WebTestCase
{
    /** @return array{0: array<string,string>, 1: User} */
    private function auth(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($em, $hasher))->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function fixtures(): RecommendationRunFixtures
    {
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        self::assertInstanceOf(ApiKeyCipher::class, $cipher);

        return new RecommendationRunFixtures($this->em(), $cipher);
    }

    /**
     * The provider price is banked through raw SQL arithmetic in production
     * (RecordedCall::bankUsage(), never through the entity — see
     * ProviderUsage's class doc), so a fixture that wants a priced run has to
     * write the same column the same way rather than call a setter that does
     * not exist.
     */
    private function priceRun(RecommendationRun $run, int $costNanoCredits): void
    {
        $id = $run->getId();
        self::assertNotNull($id);

        $this->em()->getConnection()->executeStatement(
            'UPDATE recommendation_run SET cost_nano_credits = :cost WHERE id = :id',
            ['cost' => $costNanoCredits, 'id' => $id],
        );
        $this->em()->clear();
    }

    public function testAnswersWithTheAccountsRunsNewestFirstAndTheAllTimeTotal(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('run-history-mine@example.test');
        [, $otherUser] = $this->auth('run-history-theirs@example.test');

        $older = $this->fixtures()->createRun($user);
        $older->stampProvider('openrouter.ai', 'x-ai/grok-4-fast');
        $older->snapshot([[1]]);
        $older->recordBatchWinners([]);
        $older->complete(new \DateTimeImmutable('2026-08-16 09:00:10'));
        $this->em()->flush();
        $this->priceRun($older, 1_000);

        // priceRun() clears the identity map, so $older and $user must be
        // re-fetched before they are used as Doctrine associations again.
        $user = $this->em()->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $user);

        $newer = $this->fixtures()->createRun($user);
        $newer->stampProvider('openrouter.ai', 'x-ai/grok-4-fast');
        $newer->snapshot([[1]]);
        $newer->recordBatchWinners([]);
        $newer->complete(new \DateTimeImmutable('2026-08-16 09:05:10'));
        $this->em()->flush();
        $this->priceRun($newer, 2_000);

        $otherUser = $this->em()->getRepository(User::class)->find($otherUser->getId());
        self::assertInstanceOf(User::class, $otherUser);
        $theirRun = $this->fixtures()->createRun($otherUser);
        $theirRun->stampProvider('example.test', 'their-model');
        $theirRun->snapshot([[1]]);
        $theirRun->recordBatchWinners([]);
        $theirRun->complete(new \DateTimeImmutable('2026-08-16 09:05:10'));
        $this->em()->flush();
        $this->priceRun($theirRun, 9_999);

        $client->request('GET', '/api/recommendations/runs/history', server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
        self::assertSame(3_000, $payload['totalCostNanoCredits']);
        /** @var list<array<string, mixed>> $runs */
        $runs = $payload['runs'];
        self::assertCount(2, $runs);
        self::assertSame('openrouter.ai', $runs[0]['providerHost']);
        self::assertSame(2_000, $runs[0]['costNanoCredits']);
        self::assertSame(1_000, $runs[1]['costNanoCredits']);
    }

    public function testAnAccountThatNeverRanGetsAnEmptyHistoryAndNoTotal(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('run-history-empty@example.test');

        $client->request('GET', '/api/recommendations/runs/history', server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
        self::assertSame([], $payload['runs']);
        self::assertNull($payload['totalCostNanoCredits']);
    }

    public function testRefusesAnAnonymousRequest(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/recommendations/runs/history');

        self::assertResponseStatusCodeSame(401);
    }
}
