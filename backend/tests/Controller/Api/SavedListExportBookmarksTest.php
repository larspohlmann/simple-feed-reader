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
use App\Tests\Support\NamesLabels;
use App\Tests\Support\StateMarks;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class SavedListExportBookmarksTest extends ApiTestCase
{
    use NamesLabels;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testItDownloadsTheKeptListAsABookmarkFile(): void
    {
        [$headers, $user] = $this->bearerAndUser('export-bookmarks-kept@example.com');
        $entry = $this->keptEntry($user, $this->subscribe($user), 'Entry One', 'https://export.example/1');
        $this->label($user, $entry, 'Alpha');
        $this->label($user, $entry, 'Beta');

        $body = $this->download($headers, 'view=kept');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('text/html; charset=UTF-8', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(
            sprintf('attachment; filename="simplefeedreader-kept-%s.html"', $this->today()),
            $this->client->getResponse()->headers->get('Content-Disposition'),
        );
        self::assertStringStartsWith('<!DOCTYPE NETSCAPE-Bookmark-file-1>', $body);
        self::assertStringContainsString('<H3>Kept</H3>', $body);
        self::assertStringContainsString(
            sprintf(
                '<DT><A HREF="https://export.example/1" ADD_DATE="%d" TAGS="Alpha,Beta">Entry One</A>',
                (new \DateTimeImmutable('2026-07-01T00:00:00Z'))->getTimestamp(),
            ),
            $body,
        );
    }

    public function testALabelExportIsNamedAfterTheLabel(): void
    {
        [$headers, $user] = $this->bearerAndUser('export-bookmarks-label@example.com');
        $entry = $this->keptEntry($user, $this->subscribe($user), 'Labelled', 'https://export.example/l');
        $label = $this->label($user, $entry, 'Reading list');

        $body = $this->download($headers, 'view=kept&label=' . $label->requireId());

        self::assertStringContainsString('<H3>Kept – Reading list</H3>', $body);
        self::assertStringContainsString(
            '-kept-reading-list-',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    public function testUnlabeledExportsOnlyEntriesWithoutALabel(): void
    {
        [$headers, $user] = $this->bearerAndUser('export-bookmarks-unlabeled@example.com');
        $feed = $this->subscribe($user);
        $labelled = $this->keptEntry($user, $feed, 'Has label', 'https://export.example/has');
        $this->label($user, $labelled, 'Work');
        $this->keptEntry($user, $feed, 'No label', 'https://export.example/none');

        $body = $this->download($headers, 'view=kept&label=unlabeled');

        self::assertStringContainsString('<H3>Kept – Unlabeled</H3>', $body);
        self::assertStringContainsString('>No label</A>', $body);
        self::assertStringNotContainsString('Has label', $body);
    }

    public function testMarkupInTitlesUrlsAndLabelsIsEscaped(): void
    {
        [$headers, $user] = $this->bearerAndUser('export-bookmarks-escape@example.com');
        $entry = $this->keptEntry($user, $this->subscribe($user), 'Tom & "Jerry" <b>', 'https://x.example/?a=1&b=2');
        $label = $this->label($user, $entry, 'A, "B" & <C>');

        $body = $this->download($headers, 'view=kept&label=' . $label->requireId());

        self::assertStringContainsString('Tom &amp; &quot;Jerry&quot; &lt;b&gt;</A>', $body);
        self::assertStringContainsString('HREF="https://x.example/?a=1&amp;b=2"', $body);
        self::assertStringContainsString('TAGS="A  &quot;B&quot; &amp; &lt;C&gt;"', $body);
        self::assertStringContainsString('<H3>Kept – A, &quot;B&quot; &amp; &lt;C&gt;</H3>', $body);
    }

    public function testAnEntryWithoutAUrlIsSkipped(): void
    {
        [$headers, $user] = $this->bearerAndUser('export-bookmarks-nourl@example.com');
        $feed = $this->subscribe($user);
        $this->keptEntry($user, $feed, 'Without url', null);
        $this->keptEntry($user, $feed, 'With url', 'https://export.example/with');

        $body = $this->download($headers, 'view=kept');

        self::assertStringNotContainsString('Without url', $body);
        self::assertStringContainsString('>With url</A>', $body);
    }

    public function testAnEmptyListIsAValidEmptyFolder(): void
    {
        [$headers] = $this->bearerAndUser('export-bookmarks-empty@example.com');

        $body = $this->download($headers, 'view=favorites');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringStartsWith('<!DOCTYPE NETSCAPE-Bookmark-file-1>', $body);
        self::assertStringContainsString("<H3>Favorites</H3>\n<DL><p>\n</DL><p>\n</DL><p>", $body);
    }

    public function testAddDateIsTheUtcEffectiveDate(): void
    {
        [$headers, $user] = $this->bearerAndUser('export-bookmarks-date@example.com');
        $this->keptEntry($user, $this->subscribe($user), 'Dated', 'https://export.example/d');

        $body = $this->download($headers, 'view=kept');

        $expected = (new \DateTimeImmutable('2026-07-01T00:00:00Z'))->getTimestamp();
        self::assertStringContainsString('ADD_DATE="' . $expected . '"', $body);
    }

    public function testTheAllViewIsAValidationError(): void
    {
        [$headers] = $this->bearerAndUser('export-bookmarks-all@example.com');

        $this->client->request('GET', '/api/exports/bookmarks?view=all', server: $headers);

        $this->assertRejected($this->client, Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = $this->payload($this->client);
        self::assertSame('validation_error', $body['type']);
        self::assertSame(['view' => ['An export needs view=favorites or view=kept.']], $body['errors']);
    }

    public function testAnotherUsersLabelIsNotFound(): void
    {
        [$headers] = $this->bearerAndUser('export-bookmarks-own@example.com');
        $stranger = $this->factory()->create('export-bookmarks-stranger@example.com');
        $foreign = $this->labelNamed($stranger, 'Theirs');

        $query = 'view=kept&label=' . $foreign->requireId();
        $this->client->request('GET', '/api/exports/bookmarks?' . $query, server: $headers);

        $this->assertRejected($this->client, Response::HTTP_NOT_FOUND);
    }

    public function testItNeedsAToken(): void
    {
        $this->client->request('GET', '/api/exports/bookmarks?view=kept');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    private function today(): string
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return $clock->now()->format('Ymd');
    }

    /** @param array<string, string> $headers */
    private function download(array $headers, string $queryString): string
    {
        $this->client->request('GET', '/api/exports/bookmarks?' . $queryString, server: $headers);

        return (string) $this->client->getInternalResponse()->getContent();
    }

    private function subscribe(User $user): Feed
    {
        $feed = new Feed('https://export.example/' . uniqid('', true) . '.xml');
        $this->entityManager()->persist($feed);
        $this->entityManager()->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->entityManager()->flush();

        return $feed;
    }

    private function keptEntry(User $user, Feed $feed, string $title, ?string $url): Entry
    {
        $moment = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $entry = new Entry($feed, uniqid($title, true), $url, $title, $moment, $moment);
        $this->entityManager()->persist($entry);
        $state = new EntryState($user, $entry);
        StateMarks::markKept($state);
        $this->entityManager()->persist($state);
        $this->entityManager()->flush();

        return $entry;
    }

    private function label(User $user, Entry $entry, string $name): Label
    {
        $label = $this->labelNamed($user, $name);
        $this->entityManager()->persist(new EntryLabel($label, $entry));
        $this->entityManager()->flush();

        return $label;
    }
}
