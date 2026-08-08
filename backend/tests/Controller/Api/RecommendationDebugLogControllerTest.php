<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\RecommendationRunLog;
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
 * The read side of the recommendation debug view (#309): a cheap list poll
 * and a per-row detail fetch. Both sit behind the same bearer auth as the
 * rest of the API and carry no rate limiter, same stance as `/current`.
 */
final class RecommendationDebugLogControllerTest extends WebTestCase
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

    public function testListReturnsEntriesWithStreamingTextOnlyForTheOpenCall(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('debug-log-list@example.test');
        $run = $this->fixtures()->createRun($user);
        $finished = $this->fixtures()->log($run, RecommendationRunLog::PHASE_BATCH, 1, 1, 'req a');
        $finished->finish('done text', RecommendationRunLog::VERDICT_USABLE);
        $open = $this->fixtures()->log($run, RecommendationRunLog::PHASE_BATCH, 2, 1, 'req b');
        $this->em()->flush();

        $client->request('GET', '/api/recommendations/runs/debug-log', server: $headers);

        self::assertResponseIsSuccessful();
        $entries = $this->payload($client->getResponse())['entries'];
        self::assertIsArray($entries);
        self::assertSame(
            [
                'id' => $finished->getId(),
                'phase' => 'batch',
                'batchNumber' => 1,
                'attempt' => 1,
                'verdict' => 'usable',
                'requestBytes' => \strlen('req a'),
                'responseBytes' => \strlen('done text'),
                'streamingText' => null,
            ],
            $entries[0],
        );
        self::assertSame(
            [
                'id' => $open->getId(),
                'phase' => 'batch',
                'batchNumber' => 2,
                'attempt' => 1,
                'verdict' => null,
                'requestBytes' => \strlen('req b'),
                'responseBytes' => 0,
                'streamingText' => '',
            ],
            $entries[1],
        );
    }

    public function testListIsEmptyForAUserWithoutLogs(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('debug-log-empty@example.test');

        $client->request('GET', '/api/recommendations/runs/debug-log', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(['entries' => []], $this->payload($client->getResponse()));
    }

    public function testDetailReturnsFullBodies(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('debug-log-detail@example.test');
        $run = $this->fixtures()->createRun($user);
        $log = $this->fixtures()->log($run, RecommendationRunLog::PHASE_BATCH, 1, 1, 'req');
        $log->finish('res', RecommendationRunLog::VERDICT_USABLE);
        $this->em()->flush();
        $id = $log->getId();
        self::assertNotNull($id);

        $client->request('GET', '/api/recommendations/runs/debug-log/' . $id, server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'id' => $id,
                'phase' => 'batch',
                'batchNumber' => 1,
                'attempt' => 1,
                'verdict' => 'usable',
                'requestBody' => 'req',
                'responseText' => 'res',
            ],
            $this->payload($client->getResponse()),
        );
    }

    public function testDetailOfAnotherUsersRowIs404ProblemJson(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('debug-log-detail-mine@example.test');
        [, $otherUser] = $this->auth('debug-log-detail-theirs@example.test');
        $theirRun = $this->fixtures()->createRun($otherUser);
        $theirLog = $this->fixtures()->log($theirRun, RecommendationRunLog::PHASE_BATCH, 1, 1, 'req');
        $this->em()->flush();
        $id = $theirLog->getId();
        self::assertNotNull($id);

        $client->request('GET', '/api/recommendations/runs/debug-log/' . $id, server: $headers);

        self::assertResponseStatusCodeSame(404);
        self::assertStringStartsWith(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }
}
