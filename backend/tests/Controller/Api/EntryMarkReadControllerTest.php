<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Dto\Entry\MarkEntriesReadRequest;
use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Enum\RecommendationEngineKind;
use App\Repository\EntryStateRepository;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\SeedsFeedEntries;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class EntryMarkReadControllerTest extends ApiTestCase
{
    use SeedsFeedEntries;

    public function testMarkForYouReadClearsThePicksWithoutMovingTheWatermark(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('e-foryou-mark@example.com');
        $subscription = $this->seedFeedWithEntries($user, 2);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entries = $entityManager
            ->getRepository(Entry::class)
            ->findBy(['feed' => $subscription->getFeed()], ['guid.value' => 'ASC']);

        // Only the first entry is recommended: the second proves the action
        // stays inside the for-you list instead of clearing the whole feed.
        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $entityManager->persist($run);
        $entityManager->persist(new RecommendationItem($run, $entries[0], 1, 'reason', 50));
        $entityManager->flush();

        $client->request(
            'POST',
            '/api/entries/for-you/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['until' => '2026-08-07T12:00:00+00:00'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/entries?view=for-you&unread=1', server: $headers);
        self::assertCount(0, $this->entriesOf($client), 'Every pick is read now.');

        $client->request('GET', '/api/entries?view=unread', server: $headers);
        $stillUnread = $this->entriesOf($client);
        self::assertCount(1, $stillUnread, 'The entry that was never recommended stays unread.');

        // The watermark is what emptied the candidate pool in #665; a for-you
        // mark-read must never touch it.
        $entityManager->clear();
        $reloaded = $entityManager->getRepository(Subscription::class)->find($subscription->getId());
        self::assertInstanceOf(Subscription::class, $reloaded);
        self::assertNull($reloaded->getMarkedReadUntil());
    }

    public function testMarkReadAllThenListUnreadIsEmpty(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('e-markread@example.com');
        $this->seedFeedWithEntries($user, 3);

        $client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['scope' => 'all', 'until' => '2026-08-01T00:00:00Z'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/entries?view=unread', server: $headers);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entries']);
        self::assertCount(0, $body['entries']);
    }

    public function testMarkReadRejectsBadTimestamp(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('e-markbad@example.com'));
        $client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['scope' => 'all', 'until' => 'nonsense'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @param array<string, string>       $payload
     * @param array<string, list<string>> $errors
     */
    #[DataProvider('invalidMarkReadPayloads')]
    public function testMarkReadValidationErrorsKeepTheirKeysAndMessages(array $payload, array $errors): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('e-markinvalid@example.com'));
        $client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload + ['until' => '2026-08-01T00:00:00Z'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']);
        self::assertSame($errors, $body['errors']);
    }

    /** @return iterable<string, array{array<string, string>, array<string, list<string>>}> */
    public static function invalidMarkReadPayloads(): iterable
    {
        yield 'feed without an id' => [
            ['scope' => 'feed'],
            ['id' => ['An id is required when scope is "feed".']],
        ];
        yield 'tag without an id' => [
            ['scope' => 'tag'],
            ['id' => ['An id is required when scope is "tag".']],
        ];
        yield 'unknown scope' => [
            ['scope' => 'bogus'],
            ['scope' => ['The value you selected is not a valid choice.']],
        ];
    }

    public function testMarkReadBatchMarksOnlyTheGivenEntries(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('e-markbatch@example.com');
        $subscription = $this->seedFeedWithEntries($user, 3);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entries = $entityManager
            ->getRepository(Entry::class)
            ->findBy(['feed' => $subscription->getFeed()], ['guid.value' => 'ASC']);
        $markIds = [$entries[0]->requireId(), $entries[1]->requireId()];

        $this->postMarkReadBatch($client, $headers, $markIds);
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/entries?view=unread', server: $headers);
        self::assertCount(1, $this->entriesOf($client), 'The unmarked entry stays unread.');
    }

    public function testMarkReadBatchToleratesDuplicateIds(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('e-markbatch-dup@example.com');
        $subscription = $this->seedFeedWithEntries($user, 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entry = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $subscription->getFeed()]);
        self::assertInstanceOf(Entry::class, $entry);
        $id = $entry->requireId();

        $this->postMarkReadBatch($client, $headers, [$id, $id]);
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/entries?view=unread', server: $headers);
        self::assertCount(0, $this->entriesOf($client), 'The duplicated id is marked read exactly once.');
    }

    public function testMarkReadBatchIgnoresNonexistentIds(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('e-markbatch-missing@example.com');
        $subscription = $this->seedFeedWithEntries($user, 1);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entry = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $subscription->getFeed()]);
        self::assertInstanceOf(Entry::class, $entry);
        $id = $entry->requireId();
        $missingId = 99999999;
        self::assertNull($entityManager->getRepository(Entry::class)->find($missingId));

        $this->postMarkReadBatch($client, $headers, [$id, $missingId]);
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/entries?view=unread', server: $headers);
        self::assertCount(0, $this->entriesOf($client), 'The existing id is marked read; the missing one is ignored.');

        // No state row at all: `findExistingIds()` dropped the id, not a dialect's FK check.
        $states = self::getContainer()->get(EntryStateRepository::class);
        self::assertInstanceOf(EntryStateRepository::class, $states);
        $userId = $user->requireId();
        self::assertNull($states->findOneForUserEntry($userId, $missingId));
        $existingState = $states->findOneForUserEntry($userId, $id);
        self::assertInstanceOf(EntryState::class, $existingState);
        self::assertTrue($existingState->isHidden());
    }

    public function testMarkReadBatchRejectsEmptyIds(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('e-markbatch-empty@example.com'));

        $this->postMarkReadBatch($client, $headers, []);
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']);
    }

    public function testMarkReadBatchRejectsNonPositiveIds(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('e-markbatch-neg@example.com'));

        $this->postMarkReadBatch($client, $headers, [0, -5]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testMarkReadBatchRejectsOverTheCap(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('e-markbatch-cap@example.com'));

        $this->postMarkReadBatch($client, $headers, range(1, MarkEntriesReadRequest::MAX_IDS + 1));
        self::assertResponseStatusCodeSame(422);
    }

    public function testMarkReadBatchRejectsAnonymous(): void
    {
        $client = self::createClient();

        $this->postMarkReadBatch($client, [], [1]);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @param array<string, string> $headers
     * @param list<int> $ids
     */
    private function postMarkReadBatch(KernelBrowser $client, array $headers, array $ids): void
    {
        $client->request(
            'POST',
            '/api/entries/mark-read-batch',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ids' => $ids], \JSON_THROW_ON_ERROR),
        );
    }
}
