<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\EntryLabel;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Label;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\StateMarks;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class LabelControllerTest extends ApiTestCase
{
    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/labels');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testNewAccountListsReadLater(): void
    {
        $client = self::createClient();
        $headers = $this->jsonBearerFor($this->factory()->create('label-new@example.com'));

        $body = $this->send($client, 'GET', '/api/labels', $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'labels' => [
                    $this->tally($this->firstLabelId($body), 'Read later', 0, 0, 0),
                ],
                'unlabeled' => ['favoriteCount' => 0, 'keptCount' => 0],
            ],
            $body,
        );
    }

    public function testCreateTrimsAndRejectsCaseInsensitiveDuplicate(): void
    {
        $client = self::createClient();
        $headers = $this->jsonBearerFor($this->factory()->create('label-create@example.com'));

        $created = $this->send($client, 'POST', '/api/labels', $headers, ['name' => '  Work ']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertIsArray($created['label']);
        self::assertSame('Work', $created['label']['name']);

        $conflict = $this->send($client, 'POST', '/api/labels', $headers, ['name' => 'work']);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('label_name_taken', $conflict['type']);

        $longest = str_repeat('a', 60);
        $this->send($client, 'POST', '/api/labels', $headers, ['name' => $longest]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->send($client, 'POST', '/api/labels', $headers, ['name' => $longest . 'a']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $blank = $this->send($client, 'POST', '/api/labels', $headers, ['name' => '   ']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('validation_error', $blank['type']);
    }

    public function testRenameToOwnCaseVariantSucceeds(): void
    {
        $client = self::createClient();
        $headers = $this->jsonBearerFor($this->factory()->create('label-rename@example.com'));
        $listed = $this->send($client, 'GET', '/api/labels', $headers);
        $readLaterId = $this->firstLabelId($listed);
        $created = $this->send($client, 'POST', '/api/labels', $headers, ['name' => 'Work']);
        self::assertIsArray($created['label']);
        $workId = $created['label']['id'];
        self::assertIsInt($workId);

        $renamed = $this->send($client, 'PATCH', '/api/labels/' . $readLaterId, $headers, ['name' => 'READ LATER']);
        self::assertResponseIsSuccessful();
        self::assertSame(['label' => ['id' => $readLaterId, 'name' => 'READ LATER']], $renamed);

        $this->send($client, 'PATCH', '/api/labels/' . $workId, $headers, ['name' => str_repeat('a', 61)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $conflict = $this->send($client, 'PATCH', '/api/labels/' . $workId, $headers, ['name' => 'read later']);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('label_name_taken', $conflict['type']);
    }

    public function testForeignLabelIsNotFound(): void
    {
        $client = self::createClient();
        $ownerHeaders = $this->jsonBearerFor($this->factory()->create('label-owner@example.com'));
        $foreignId = $this->firstLabelId($this->send($client, 'GET', '/api/labels', $ownerHeaders));
        $headers = $this->jsonBearerFor($this->factory()->create('label-intruder@example.com'));

        $this->send($client, 'PATCH', '/api/labels/' . $foreignId, $headers, ['name' => 'Mine now']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->send($client, 'DELETE', '/api/labels/' . $foreignId, $headers);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCountsSplitByCollectionAndCountEachArticleOnce(): void
    {
        $client = self::createClient();
        $entityManager = $this->entityManager();
        $user = $this->factory()->create('label-counts@example.com');
        $headers = $this->jsonBearerFor($user);
        $work = new Label($user, 'Work');
        $entityManager->persist($work);
        $subscription = $this->subscribe($user);
        $entryA = $this->entry($subscription->getFeed(), 'a');
        $entryB = $this->entry($subscription->getFeed(), 'b');
        $entryC = $this->entry($subscription->getFeed(), 'c');
        $leftBoth = $this->entry($subscription->getFeed(), 'd');
        $this->state($user, $entryA, favorite: true, kept: true);
        $this->state($user, $entryB, favorite: false, kept: true);
        $this->state($user, $entryC, favorite: true, kept: false);
        $this->state($user, $leftBoth, favorite: false, kept: false);
        $entityManager->persist(new EntryLabel($work, $entryA));
        $entityManager->persist(new EntryLabel($work, $entryC));
        $entityManager->persist(new EntryLabel($work, $leftBoth));
        $entityManager->flush();

        $body = $this->send($client, 'GET', '/api/labels', $headers);

        self::assertSame(
            [
                $this->tally($this->firstLabelId($body), 'Read later', 0, 0, 0),
                $this->tally($work->requireId(), 'Work', 2, 1, 2),
            ],
            $body['labels'],
        );
        self::assertSame(['favoriteCount' => 0, 'keptCount' => 1], $body['unlabeled']);

        $entityManager->remove($subscription);
        $entityManager->flush();

        $unsubscribed = $this->send($client, 'GET', '/api/labels', $headers);
        self::assertIsArray($unsubscribed['labels']);
        foreach ($unsubscribed['labels'] as $label) {
            self::assertIsArray($label);
            self::assertSame([0, 0, 0], [$label['favoriteCount'], $label['keptCount'], $label['articleCount']]);
        }
        self::assertSame(['favoriteCount' => 0, 'keptCount' => 0], $unsubscribed['unlabeled']);
    }

    public function testListIsAlphabeticalIgnoringCase(): void
    {
        $client = self::createClient();
        $headers = $this->jsonBearerFor($this->factory()->create('label-order@example.com'));
        $this->send($client, 'POST', '/api/labels', $headers, ['name' => 'zebra']);
        $this->send($client, 'POST', '/api/labels', $headers, ['name' => 'Apple']);

        $body = $this->send($client, 'GET', '/api/labels', $headers);

        self::assertIsArray($body['labels']);
        self::assertSame(['Apple', 'Read later', 'zebra'], array_column($body['labels'], 'name'));
    }

    public function testDeleteRemovesItFromEveryEntry(): void
    {
        $client = self::createClient();
        $entityManager = $this->entityManager();
        $user = $this->factory()->create('label-delete@example.com');
        $label = new Label($user, 'Doomed');
        $entityManager->persist($label);
        $entry = $this->entry($this->subscribe($user)->getFeed(), 'x');
        $this->state($user, $entry, favorite: true, kept: false);
        $entityManager->persist(new EntryLabel($label, $entry));
        $entityManager->flush();
        $labelId = $label->requireId();
        $entryId = $entry->requireId();

        $this->send($client, 'DELETE', '/api/labels/' . $labelId, $this->jsonBearerFor($user));

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(0, $this->countRows('entry_label', 'label_id', $labelId));
        self::assertSame(1, $this->countRows('entry_state', 'entry_id', $entryId));
        self::assertSame(0, $this->countRows('label', 'id', $labelId));
    }

    private function countRows(string $table, string $column, int $value): int
    {
        $count = $this->entityManager()->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = ?', [$value]);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /** @return array<string, int|string> */
    private function tally(int $id, string $name, int $favorites, int $kept, int $articles): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'favoriteCount' => $favorites,
            'keptCount' => $kept,
            'articleCount' => $articles,
        ];
    }

    private function subscribe(User $user): Subscription
    {
        $feed = new Feed('https://label-counts.example/' . uniqid('', true) . '.xml');
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager()->persist($feed);
        $this->entityManager()->persist($subscription);
        $this->entityManager()->flush();

        return $subscription;
    }

    private function entry(Feed $feed, string $guid): Entry
    {
        $moment = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $entry = new Entry($feed, $guid, 'https://label-counts.example/' . $guid, 'Post ' . $guid, $moment, $moment);
        $this->entityManager()->persist($entry);
        $this->entityManager()->flush();

        return $entry;
    }

    private function state(User $user, Entry $entry, bool $favorite, bool $kept): void
    {
        $state = new EntryState($user, $entry);
        if ($favorite) {
            StateMarks::markFavorite($state);
        }
        if ($kept) {
            StateMarks::markKept($state);
        }
        $this->entityManager()->persist($state);
        $this->entityManager()->flush();
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string>|null $payload
     * @return array<mixed>
     */
    private function send(
        KernelBrowser $client,
        string $method,
        string $uri,
        array $headers,
        ?array $payload = null,
    ): array {
        $client->request(
            $method,
            $uri,
            server: $headers,
            content: null === $payload ? null : json_encode($payload, \JSON_THROW_ON_ERROR),
        );
        $content = (string) $client->getResponse()->getContent();
        if ('' === $content) {
            return [];
        }
        $body = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    /** @param array<mixed> $body */
    private function firstLabelId(array $body): int
    {
        self::assertIsArray($body['labels']);
        $first = $body['labels'][0];
        self::assertIsArray($first);
        self::assertIsInt($first['id']);

        return $first['id'];
    }
}
