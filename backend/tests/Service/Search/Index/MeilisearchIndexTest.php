<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Index;

use App\Http\EntryCursor;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Index\IndexedEntry;
use App\Service\Search\Index\IndexSearch;
use App\Service\Search\Index\MeilisearchIndex;
use App\Service\Search\SearchEngineCapability;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MeilisearchIndexTest extends TestCase
{
    private const string BASE_URL = 'http://meilisearch.test:7700';
    private const string API_KEY = 'test-master-key';

    /** @var array{method: string, url: string, body: ?string, headers: list<string>} */
    private array $capturedRequest = ['method' => '', 'url' => '', 'body' => null, 'headers' => []];

    private function index(MockHttpClient $client): MeilisearchIndex
    {
        return new MeilisearchIndex($client, new SearchEngineCapability(self::BASE_URL, self::API_KEY));
    }

    private function search(): IndexSearch
    {
        return new IndexSearch(['widgets', 'gizmos'], [1, 2], null, 20);
    }

    /**
     * A client that answers every request with $response and records the one
     * request it received in $this->capturedRequest — every test in this file
     * makes exactly one call through the adapter, so one captured request is
     * always enough.
     */
    private function clientCapturing(MockResponse $response): MockHttpClient
    {
        return new MockHttpClient(
            function (string $method, string $url, array $options) use ($response): MockResponse {
                $body = $options['body'] ?? null;
                $headers = $options['headers'] ?? [];
                $this->capturedRequest = [
                    'method' => $method,
                    'url' => $url,
                    'body' => \is_string($body) ? $body : null,
                    'headers' => \is_array($headers) ? array_values(array_filter($headers, 'is_string')) : [],
                ];

                return $response;
            },
        );
    }

    /** @return array<string, mixed> the captured request's body, decoded as a JSON object */
    private function capturedJsonObject(): array
    {
        $decoded = json_decode((string) $this->capturedRequest['body'], true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return list<mixed> the captured request's body, decoded as a JSON array */
    private function capturedJsonList(): array
    {
        $decoded = json_decode((string) $this->capturedRequest['body'], true);
        self::assertIsArray($decoded);

        /** @var list<mixed> $decoded */
        return $decoded;
    }

    // --- find(): request shape ---------------------------------------

    public function testFindSendsMatchingStrategyAllAndTheTermsJoinedBySpace(): void
    {
        $this->index($this->clientCapturing(new MockResponse('{"hits":[]}')))->find($this->search());

        $decoded = $this->capturedJsonObject();
        self::assertSame('all', $decoded['matchingStrategy']);
        self::assertSame('widgets gizmos', $decoded['q']);
    }

    public function testFindSendsTheFeedIdFilter(): void
    {
        $client = $this->clientCapturing(new MockResponse('{"hits":[]}'));
        $this->index($client)->find(new IndexSearch(['widgets'], [3, 7], null, 20));

        self::assertSame('feedId IN [3,7]', $this->capturedJsonObject()['filter']);
    }

    public function testACursorAddsTheCompoundEffectiveDateIdPredicate(): void
    {
        $client = $this->clientCapturing(new MockResponse('{"hits":[]}'));
        $cursor = new EntryCursor(new \DateTimeImmutable('@100'), 5);
        $this->index($client)->find(new IndexSearch(['widgets'], [1, 2], $cursor, 20));

        self::assertSame(
            'feedId IN [1,2] AND (effectiveDate < 100 OR (effectiveDate = 100 AND id < 5))',
            $this->capturedJsonObject()['filter'],
        );
    }

    public function testNoCursorAddsNoCursorPredicate(): void
    {
        $client = $this->clientCapturing(new MockResponse('{"hits":[]}'));
        $this->index($client)->find(new IndexSearch(['widgets'], [1, 2], null, 20));

        $filter = $this->capturedJsonObject()['filter'];
        self::assertSame('feedId IN [1,2]', $filter);
        self::assertStringNotContainsString('effectiveDate', $filter);
    }

    public function testFindSortsByEffectiveDateThenIdBothDescending(): void
    {
        $this->index($this->clientCapturing(new MockResponse('{"hits":[]}')))->find($this->search());

        self::assertSame(['effectiveDate:desc', 'id:desc'], $this->capturedJsonObject()['sort']);
    }

    public function testFindRetrievesOnlyIdAndHighlightsTitleAndSummary(): void
    {
        $this->index($this->clientCapturing(new MockResponse('{"hits":[]}')))->find($this->search());

        $decoded = $this->capturedJsonObject();
        self::assertSame(['id'], $decoded['attributesToRetrieve']);
        self::assertSame(['title', 'summary'], $decoded['attributesToHighlight']);
        // Sentinel tags, not <mark> — see MeilisearchIndex's class docblock for why.
        self::assertSame('[[sfr:hl]]', $decoded['highlightPreTag']);
        self::assertSame('[[/sfr:hl]]', $decoded['highlightPostTag']);
    }

    public function testFindSendsTheLimit(): void
    {
        $client = $this->clientCapturing(new MockResponse('{"hits":[]}'));
        $this->index($client)->find(new IndexSearch(['widgets'], [1], null, 7));

        self::assertSame(7, $this->capturedJsonObject()['limit']);
    }

    public function testFindPostsToTheEntriesSearchEndpoint(): void
    {
        $this->index($this->clientCapturing(new MockResponse('{"hits":[]}')))->find($this->search());

        self::assertSame('POST', $this->capturedRequest['method']);
        self::assertSame(self::BASE_URL . '/indexes/entries/search', $this->capturedRequest['url']);
    }

    // --- find(): response shape ---------------------------------------

    public function testFindReturnsHitIdsInTheEnginesOrder(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'hits' => [['id' => 9], ['id' => 3], ['id' => 17]],
        ], JSON_THROW_ON_ERROR)));

        $matches = $this->index($client)->find($this->search());

        self::assertSame([9, 3, 17], $matches->entryIds);
    }

    public function testMatchedWordsAreExtractedFromTheHighlightTagsDeduplicatedCasePreserved(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'hits' => [
                [
                    'id' => 1,
                    '_formatted' => [
                        'title' => 'How to [[sfr:hl]]Receive[[/sfr:hl]] a package',
                        'summary' => 'A guide about [[sfr:hl]]Receive[[/sfr:hl]]ing packages, '
                            . '[[sfr:hl]]Widgets[[/sfr:hl]] included.',
                    ],
                ],
                [
                    'id' => 2,
                    '_formatted' => [
                        'title' => 'Some [[sfr:hl]]Widgets[[/sfr:hl]] and gizmos',
                        'summary' => 'No highlight here',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR)));

        $matches = $this->index($client)->find($this->search());

        // "Receive" appears twice (title + summary of hit 1) and is kept once;
        // case is preserved exactly as the engine highlighted it.
        self::assertSame(['Receive', 'Widgets'], $matches->matchedWords);
    }

    public function testAHitWithNoHighlightsContributesNoMatchedWords(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'hits' => [['id' => 1, '_formatted' => ['title' => 'Plain title', 'summary' => 'Plain summary']]],
        ], JSON_THROW_ON_ERROR)));

        $matches = $this->index($client)->find($this->search());

        self::assertSame([], $matches->matchedWords);
    }

    // --- find(): failures -----------------------------------------------

    public function testATransportFailureBecomesSearchEngineUnavailable(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });

        $this->expectException(SearchEngineUnavailableException::class);
        $this->index($client)->find($this->search());
    }

    public function testAServerErrorBecomesSearchEngineUnavailable(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 500]));

        $this->expectException(SearchEngineUnavailableException::class);
        $this->index($client)->find($this->search());
    }

    public function testAClientErrorBecomesSearchEngineUnavailable(): void
    {
        $client = new MockHttpClient(new MockResponse('{"message":"bad filter"}', ['http_code' => 400]));

        $this->expectException(SearchEngineUnavailableException::class);
        $this->index($client)->find($this->search());
    }

    public function testAnUnreadableResponseBecomesSearchEngineUnavailable(): void
    {
        $client = new MockHttpClient(new MockResponse('not json'));

        $this->expectException(SearchEngineUnavailableException::class);
        $this->index($client)->find($this->search());
    }

    // --- shared transport shape ------------------------------------------

    public function testEveryRequestCarriesTheBearerToken(): void
    {
        $this->index($this->clientCapturing(new MockResponse('{"hits":[]}')))->find($this->search());

        self::assertContains('Authorization: Bearer test-master-key', $this->capturedRequest['headers']);
    }

    // --- configure() ----------------------------------------------------

    public function testConfigureSendsTheSettingsToThePatchEndpoint(): void
    {
        $response = new MockResponse('{"taskUid":0,"status":"enqueued"}', ['http_code' => 202]);
        $this->index($this->clientCapturing($response))->configure();

        self::assertSame('PATCH', $this->capturedRequest['method']);
        self::assertSame(self::BASE_URL . '/indexes/entries/settings', $this->capturedRequest['url']);

        $decoded = $this->capturedJsonObject();
        // Order is a behavioural contract, not cosmetic: Meilisearch's
        // attribute ranking rule ranks a hit by which attribute in this list
        // it matched, in the order declared here — title outranks summary
        // outranks content outranks feedTitle. A reordering silently changes
        // relevance without changing which entries are found, so this test
        // pins the sequence, not just the membership.
        self::assertSame(['title', 'summary', 'content', 'feedTitle'], $decoded['searchableAttributes']);
        self::assertSame(['feedId', 'effectiveDate', 'id'], $decoded['filterableAttributes']);
        self::assertSame(['effectiveDate', 'id'], $decoded['sortableAttributes']);
    }

    // --- upsert() ---------------------------------------------------------

    public function testUpsertPostsTheDocumentsWithEffectiveDateAsAUnixTimestamp(): void
    {
        $response = new MockResponse('{"taskUid":1,"status":"enqueued"}', ['http_code' => 202]);
        $client = $this->clientCapturing($response);

        $entry = new IndexedEntry(
            42,
            7,
            'How to receive a package',
            'A short summary',
            'The plain-text body',
            'Example Feed',
            new \DateTimeImmutable('@1700000000'),
        );
        $this->index($client)->upsert([$entry]);

        self::assertSame('POST', $this->capturedRequest['method']);
        // primaryKey=id is mandatory: a document carrying both `id` and
        // `feedId` defeats Meilisearch's primary-key inference (see
        // MeilisearchIndex::upsert()'s comment and the probe it links to).
        self::assertSame(
            self::BASE_URL . '/indexes/entries/documents?primaryKey=id',
            $this->capturedRequest['url'],
        );

        self::assertSame([[
            'id' => 42,
            'feedId' => 7,
            'title' => 'How to receive a package',
            'summary' => 'A short summary',
            'content' => 'The plain-text body',
            'feedTitle' => 'Example Feed',
            'effectiveDate' => 1700000000,
        ]], $this->capturedJsonList());
    }

    public function testUpsertCarriesNullableFieldsAsNull(): void
    {
        $response = new MockResponse('{"taskUid":1,"status":"enqueued"}', ['http_code' => 202]);
        $client = $this->clientCapturing($response);

        $entry = new IndexedEntry(1, 1, 'Title only', null, null, null, new \DateTimeImmutable('@0'));
        $this->index($client)->upsert([$entry]);

        $document = $this->capturedJsonList()[0];
        self::assertIsArray($document);
        self::assertNull($document['summary']);
        self::assertNull($document['content']);
        self::assertNull($document['feedTitle']);
    }

    public function testAnEmptyUpsertPerformsNoHttpCall(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('upsert([]) must not perform an HTTP call.');
        });

        $this->index($client)->upsert([]);

        self::assertSame(0, $client->getRequestsCount());
    }

    // --- forget() -----------------------------------------------------

    public function testForgetPostsTheIdListToTheDeleteBatchEndpoint(): void
    {
        $response = new MockResponse('{"taskUid":2,"status":"enqueued"}', ['http_code' => 202]);
        $this->index($this->clientCapturing($response))->forget([11, 12, 13]);

        self::assertSame('POST', $this->capturedRequest['method']);
        self::assertSame(
            self::BASE_URL . '/indexes/entries/documents/delete-batch',
            $this->capturedRequest['url'],
        );
        self::assertSame([11, 12, 13], $this->capturedJsonList());
    }

    public function testAnEmptyForgetPerformsNoHttpCall(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('forget([]) must not perform an HTTP call.');
        });

        $this->index($client)->forget([]);

        self::assertSame(0, $client->getRequestsCount());
    }

    // --- clear() --------------------------------------------------------

    public function testClearDeletesEveryDocumentButLeavesTheIndexInPlace(): void
    {
        $response = new MockResponse('{"taskUid":3,"status":"enqueued"}', ['http_code' => 202]);
        $this->index($this->clientCapturing($response))->clear();

        self::assertSame('DELETE', $this->capturedRequest['method']);
        self::assertSame(self::BASE_URL . '/indexes/entries/documents', $this->capturedRequest['url']);
    }

    // --- writer failures share the same wrapping as the reader -----------

    public function testAWriteTransportFailureBecomesSearchEngineUnavailable(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });

        $this->expectException(SearchEngineUnavailableException::class);
        $this->index($client)->configure();
    }

    public function testAWriteServerErrorBecomesSearchEngineUnavailable(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 503]));

        $this->expectException(SearchEngineUnavailableException::class);
        $this->index($client)->clear();
    }
}
