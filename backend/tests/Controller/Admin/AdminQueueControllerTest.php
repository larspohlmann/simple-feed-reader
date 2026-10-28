<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class AdminQueueControllerTest extends ApiTestCase
{
    private const string QUEUES = '/api/admin/queues';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
    }

    public function testWithoutATokenIsRejected(): void
    {
        $this->client->request('GET', self::QUEUES);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAnAdminSeesEveryQueueInOrder(): void
    {
        $this->getAs($this->factory()->create('boss@example.com', roles: ['ROLE_ADMIN']));

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'failed_messages',
                'recommendation_runs',
                'profile_runs',
                'image_verification',
                'share_enrichment_appview',
                'share_enrichment_link_cards',
                'share_enrichment_self_thread',
                'transcripts',
                'due_feeds',
            ],
            array_column($this->queues(), 'queue'),
        );
    }

    public function testAQueueReportsItsCountAndOldestTime(): void
    {
        $admin = $this->factory()->create('boss@example.com', roles: ['ROLE_ADMIN']);
        $this->entityManager()->persist(new RecommendationRun($admin, new \DateTimeImmutable('2026-10-28 07:00:00')));
        $this->entityManager()->flush();

        $this->getAs($admin);

        $runs = array_values(array_filter(
            $this->queues(),
            static fn (array $queue): bool => $queue['queue'] === 'recommendation_runs',
        ))[0];
        self::assertSame(1, $runs['waiting']);
        self::assertSame('2026-10-28T07:00:00+00:00', $runs['oldestQueuedAt']);
    }

    private function getAs(User $user): void
    {
        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);
        $this->client->request(
            'GET',
            self::QUEUES,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $manager->create($user)],
        );
    }

    /** @return list<array{queue: string, waiting: int, oldestQueuedAt: ?string}> */
    private function queues(): array
    {
        /** @var array{queues: list<array{queue: string, waiting: int, oldestQueuedAt: ?string}>} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $body['queues'];
    }
}
