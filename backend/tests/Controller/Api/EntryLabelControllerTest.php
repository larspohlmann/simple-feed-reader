<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Label;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class EntryLabelControllerTest extends ApiTestCase
{
    public function testAttachTwiceKeepsOneRow(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('entry-label-twice@example.com');
        $entry = $this->subscribedEntry($user);
        $label = $this->label($user, 'Work');

        $this->put($client, $user, $entry, $label);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->put($client, $user, $entry, $label);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        self::assertSame(1, $this->countEntryLabels($label));
    }

    public function testDetachingAnAbsentLabelIs204(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('entry-label-absent@example.com');
        $entry = $this->subscribedEntry($user);
        $label = $this->label($user, 'Work');

        $this->put($client, $user, $entry, $label);
        $this->delete($client, $user, $entry, $label);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(0, $this->countEntryLabels($label));

        $this->delete($client, $user, $entry, $label);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    public function testForeignLabelIs404(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('entry-label-foreign@example.com');
        $entry = $this->subscribedEntry($user);
        $foreignLabel = $this->label($this->factory()->create('entry-label-owner@example.com'), 'Theirs');

        $this->put($client, $user, $entry, $foreignLabel);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->delete($client, $user, $entry, $foreignLabel);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(0, $this->countEntryLabels($foreignLabel));
    }

    public function testEntryOutsideTheUsersFeedsIs404(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('entry-label-outside@example.com');
        $label = $this->label($user, 'Work');
        $strangerEntry = $this->subscribedEntry($this->factory()->create('entry-label-stranger@example.com'));

        $this->put($client, $user, $strangerEntry, $label);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->delete($client, $user, $strangerEntry, $label);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(0, $this->countEntryLabels($label));
    }

    public function testLabelSurvivesUnfavoriting(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('entry-label-survive@example.com');
        $entry = $this->subscribedEntry($user);
        $state = new EntryState($user, $entry);
        $state->markFavorite();
        $this->entityManager()->persist($state);
        $this->entityManager()->flush();
        $label = $this->readLater($user);
        $headers = $this->jsonBearerFor($user);

        $this->put($client, $user, $entry, $label);
        $this->patchState($client, $headers, $entry, ['isFavorite' => false]);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->countEntryLabels($label));
        self::assertSame(0, $this->tallyFor($client, $headers, $label)['favoriteCount']);

        $this->patchState($client, $headers, $entry, ['isFavorite' => true]);

        self::assertSame(1, $this->tallyFor($client, $headers, $label)['favoriteCount']);
    }

    private function put(KernelBrowser $client, User $user, Entry $entry, Label $label): void
    {
        $client->request('PUT', $this->path($entry, $label), server: $this->jsonBearerFor($user));
    }

    private function delete(KernelBrowser $client, User $user, Entry $entry, Label $label): void
    {
        $client->request('DELETE', $this->path($entry, $label), server: $this->jsonBearerFor($user));
    }

    private function path(Entry $entry, Label $label): string
    {
        return '/api/entries/' . $entry->requireId() . '/labels/' . $label->requireId();
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, bool> $payload
     */
    private function patchState(KernelBrowser $client, array $headers, Entry $entry, array $payload): void
    {
        $client->request(
            'PATCH',
            '/api/entries/' . $entry->requireId() . '/state',
            server: $headers,
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, string> $headers
     * @return array<mixed>
     */
    private function tallyFor(KernelBrowser $client, array $headers, Label $label): array
    {
        $client->request('GET', '/api/labels', server: $headers);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['labels']);
        foreach ($body['labels'] as $tally) {
            self::assertIsArray($tally);
            if ($tally['id'] === $label->requireId()) {
                return $tally;
            }
        }
        self::fail('Label missing from the tallies.');
    }

    private function countEntryLabels(Label $label): int
    {
        $count = $this->entityManager()->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM entry_label WHERE label_id = ?', [$label->requireId()]);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function readLater(User $user): Label
    {
        $label = $this->entityManager()->getRepository(Label::class)
            ->findOneBy(['user' => $user, 'name' => 'Read later']);
        self::assertInstanceOf(Label::class, $label);

        return $label;
    }

    private function label(User $user, string $name): Label
    {
        $label = new Label($user, $name);
        $this->entityManager()->persist($label);
        $this->entityManager()->flush();

        return $label;
    }

    private function subscribedEntry(User $user): Entry
    {
        $moment = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $feed = new Feed('https://entry-label.example/' . uniqid('', true) . '.xml');
        $entry = new Entry($feed, 'guid', 'https://entry-label.example/post', 'Post', $moment, $moment);
        $this->entityManager()->persist($feed);
        $this->entityManager()->persist(new Subscription($user, $feed, $moment));
        $this->entityManager()->persist($entry);
        $this->entityManager()->flush();

        return $entry;
    }
}
