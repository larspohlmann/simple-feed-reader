<?php

declare(strict_types=1);

namespace App\Service\Search\Index\SearchIndexReader;

use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Index\Model\IndexedEntryModel;
use App\Service\Search\Index\Model\IndexMatchesModel;
use App\Service\Search\Index\Model\IndexSearchModel;
use App\Service\Search\Index\SearchIndexWriter\SearchIndexWriterInterface;
use App\Service\Search\Model\SearchTermsModel;
use App\Service\Search\SearchEngineCapability;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The one class that knows Meilisearch's wire format (docs/meilisearch-wire-format.md), spoken as plain JSON over
 * HttpClientInterface rather than through the vendor SDK. Writes are enqueued (202) and never polled: an ingest-time
 * write must not wait on the task queue, and app:search:reindex repairs a lost one.
 */
final readonly class MeilisearchIndex implements SearchIndexReaderInterface, SearchIndexWriterInterface
{
    private const string INDEX = 'entries';

    /** A local container with the database as fallback: a hung request must fail fast, not hold a search open. */
    private const float TIMEOUT_SECONDS = 3.0;

    /**
     * Sentinels, not <mark>: a feed body can contain a literal "<mark>", which would fake or hide a match in
     * highlightedWordsIn(). Distinct open and close strings let the pattern require both ends.
     */
    private const string HIGHLIGHT_START = '[[sfr:hl]]';
    private const string HIGHLIGHT_END = '[[/sfr:hl]]';

    /**
     * Built from the probes in docs/meilisearch-wire-format.md. `sort` leads `rankingRules` because the keyset cursor
     * pages by (effectiveDate, id): a relevance rule ahead of it pulls an old match onto page one, and the cursor then
     * skips every newer one.
     *
     * @var array{
     *     searchableAttributes: list<string>,
     *     filterableAttributes: list<string>,
     *     sortableAttributes: list<string>,
     *     rankingRules: list<string>,
     * }
     */
    private const array SETTINGS = [
        'searchableAttributes' => ['title', 'summary', 'content', 'feedTitle'],
        'filterableAttributes' => ['feedId', 'effectiveDate', 'id'],
        'sortableAttributes' => ['effectiveDate', 'id'],
        'rankingRules' => ['sort', 'words', 'typo', 'proximity', 'attribute', 'exactness'],
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private SearchEngineCapability $capability,
    ) {
    }

    public function find(IndexSearchModel $search): IndexMatchesModel
    {
        $body = $this->requestBody('POST', '/indexes/' . self::INDEX . '/search', [
            'json' => $this->searchPayload($search),
        ]);

        return $this->matchesFromResponse($body);
    }

    /**
     * @param list<IndexSearchModel> $searches
     *
     * @return list<IndexMatchesModel>
     */
    public function findMany(array $searches): array
    {
        if ($searches === []) {
            return [];
        }

        $body = $this->requestBody('POST', '/multi-search', [
            'json' => ['queries' => array_map($this->multiSearchQuery(...), $searches)],
        ]);

        return $this->manyMatchesFromResponse($body, \count($searches));
    }

    public function configure(): void
    {
        $this->write('PATCH', '/indexes/' . self::INDEX . '/settings', [
            'json' => self::SETTINGS,
        ]);
    }

    public function upsert(array $entries): void
    {
        if ([] === $entries) {
            return;
        }

        // `id` and `feedId` both end in "id", so Meilisearch cannot infer the key (docs/meilisearch-wire-format.md).
        $this->write('POST', '/indexes/' . self::INDEX . '/documents?primaryKey=id', [
            'json' => array_map($this->documentOf(...), $entries),
        ]);
    }

    public function forget(array $entryIds): void
    {
        if ([] === $entryIds) {
            return;
        }

        $this->write('POST', '/indexes/' . self::INDEX . '/documents/delete-batch', [
            'json' => $entryIds,
        ]);
    }

    public function clear(): void
    {
        $this->write('DELETE', '/indexes/' . self::INDEX . '/documents');
    }

    /**
     * @return array<string, mixed>
     */
    private function searchPayload(IndexSearchModel $search): array
    {
        return [
            'q' => $this->queryStringFor($search->terms),
            'filter' => $this->filterFor($search),
            'sort' => ['effectiveDate:' . $search->order->value, 'id:' . $search->order->value],
            // Every term must match; the default ("last") drops trailing terms until something matches.
            'matchingStrategy' => 'all',
            'limit' => $search->limit,
            // `_formatted` carries title and summary from attributesToHighlight alone, so only the id is retrieved.
            'attributesToRetrieve' => ['id'],
            'attributesToHighlight' => ['title', 'summary'],
            'highlightPreTag' => self::HIGHLIGHT_START,
            'highlightPostTag' => self::HIGHLIGHT_END,
        ];
    }

    /**
     * Whole-word quotes each term: a bare term also matches by prefix and typo ("punk" found "Pünktlichkeit", #450).
     * Phrase quotes the whole term, Meilisearch's way of asking for the words in order and adjacent.
     */
    private function queryStringFor(SearchTermsModel $terms): string
    {
        $words = array_map(self::withoutPhraseDelimiters(...), $terms->terms);

        if ($terms->isPhrase) {
            return '"' . implode(' ', $words) . '"';
        }

        if (!$terms->isWholeWord) {
            return implode(' ', $words);
        }

        return implode(' ', array_map(static fn (string $word): string => '"' . $word . '"', $words));
    }

    /** A double quote would close a phrase early; as a space it is a word boundary, as in the LIKE engine. */
    private static function withoutPhraseDelimiters(string $term): string
    {
        return str_replace('"', ' ', $term);
    }

    /** @return array<string, mixed> */
    private function multiSearchQuery(IndexSearchModel $search): array
    {
        return ['indexUid' => self::INDEX, ...$this->searchPayload($search)];
    }

    private function filterFor(IndexSearchModel $search): string
    {
        $clauses = [];
        if ($search->feedIds !== null) {
            $clauses[] = sprintf('feedId IN [%s]', implode(',', $search->feedIds));
        }
        if ($search->entryIds !== null) {
            $clauses[] = sprintf('id IN [%s]', implode(',', $search->entryIds));
        }
        if ($search->cursor !== null) {
            $clauses[] = sprintf(
                '(effectiveDate %3$s %1$d OR (effectiveDate = %1$d AND id %3$s %2$d))',
                $search->cursor->sortInstant->getTimestamp(),
                $search->cursor->id,
                $search->order->strictlyAfter(),
            );
        }

        return implode(' AND ', $clauses);
    }

    private function matchesFromResponse(string $body): IndexMatchesModel
    {
        $decoded = json_decode($body, true);
        if (!\is_array($decoded) || !isset($decoded['hits']) || !\is_array($decoded['hits'])) {
            throw new SearchEngineUnavailableException('The search engine answered with an unreadable response.');
        }

        return $this->matchesFromHits($decoded['hits']);
    }

    /** @return list<IndexMatchesModel> */
    private function manyMatchesFromResponse(string $body, int $expected): array
    {
        $decoded = json_decode($body, true);
        if (!\is_array($decoded) || !isset($decoded['results']) || !\is_array($decoded['results'])) {
            throw new SearchEngineUnavailableException('The search engine answered with an unreadable response.');
        }

        /** @var list<array<mixed>> $results */
        $results = array_values(array_filter(
            $decoded['results'],
            static fn (mixed $result): bool => \is_array($result),
        ));
        if (\count($results) !== $expected) {
            throw new SearchEngineUnavailableException(
                'The search engine returned a different number of result sets than queries.',
            );
        }

        return array_map(function (array $result): IndexMatchesModel {
            $hits = isset($result['hits']) && \is_array($result['hits']) ? $result['hits'] : [];

            return $this->matchesFromHits($hits);
        }, $results);
    }

    /**
     * @param array<mixed> $rawHits
     */
    private function matchesFromHits(array $rawHits): IndexMatchesModel
    {
        /** @var list<array<mixed>> $hits */
        $hits = array_values(array_filter($rawHits, static fn (mixed $hit): bool => \is_array($hit)));

        return new IndexMatchesModel($this->entryIdsOf($hits), $this->matchedWordsOf($hits));
    }

    /**
     * @param list<array<mixed>> $hits
     *
     * @return list<int>
     */
    private function entryIdsOf(array $hits): array
    {
        $ids = [];
        foreach ($hits as $hit) {
            if (isset($hit['id']) && \is_int($hit['id'])) {
                $ids[] = $hit['id'];
            }
        }

        return $ids;
    }

    /**
     * @param list<array<mixed>> $hits
     *
     * @return list<string>
     */
    private function matchedWordsOf(array $hits): array
    {
        $words = [];
        foreach ($hits as $hit) {
            $formatted = $hit['_formatted'] ?? null;
            if (!\is_array($formatted)) {
                continue;
            }

            foreach (['title', 'summary'] as $field) {
                if (isset($formatted[$field]) && \is_string($formatted[$field])) {
                    array_push($words, ...$this->highlightedWordsIn($formatted[$field]));
                }
            }
        }

        // Deduplicated case-preserved: the same word can be highlighted in
        // both title and summary of one hit, or across several hits, and the
        // client only needs to know once that it was matched.
        return array_values(array_unique($words));
    }

    /** @return list<string> */
    private function highlightedWordsIn(string $formattedField): array
    {
        // Non-greedy: a greedy capture would swallow the text between two separate matches.
        $pattern = '/' . preg_quote(self::HIGHLIGHT_START, '/') . '(.*?)' . preg_quote(self::HIGHLIGHT_END, '/') . '/';
        preg_match_all($pattern, $formattedField, $matches);

        /** @var list<string> $captured */
        $captured = $matches[1];

        return $captured;
    }

    /**
     * @return array{
     *     id: int,
     *     feedId: int,
     *     title: string,
     *     summary: ?string,
     *     content: ?string,
     *     feedTitle: ?string,
     *     effectiveDate: int,
     * }
     */
    private function documentOf(IndexedEntryModel $entry): array
    {
        return [
            'id' => $entry->id,
            'feedId' => $entry->feedId,
            'title' => $entry->title,
            'summary' => $entry->summary,
            'content' => $entry->content,
            'feedTitle' => $entry->feedTitle,
            'effectiveDate' => $entry->effectiveDate->getTimestamp(),
        ];
    }

    /**
     * An empty MEILISEARCH_URL makes every write a no-op, not a relative-URL error on every maintenance tick. find()
     * needs no guard: EntrySearchWithFallback never reads from an unconfigured engine.
     *
     * @param array<string, mixed> $options
     *
     * @throws SearchEngineUnavailableException
     */
    private function write(string $method, string $path, array $options = []): void
    {
        if (!$this->capability->isConfigured()) {
            return;
        }

        $this->requestBody($method, $path, $options);
    }

    /**
     * Sends, reads the whole body, and turns a transport failure or a non-2xx status into the one exception every
     * caller handles.
     *
     * @param array<string, mixed> $options
     *
     * @throws SearchEngineUnavailableException
     */
    private function requestBody(string $method, string $path, array $options = []): string
    {
        try {
            $response = $this->httpClient->request($method, $this->capability->url() . $path, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->capability->key(),
                ],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
                ...$options,
            ]);

            $status = $response->getStatusCode();
            if ($status >= 300) {
                throw new SearchEngineUnavailableException(sprintf(
                    'The search engine answered with status %d.',
                    $status,
                ));
            }

            return $response->getContent();
        } catch (ExceptionInterface $exception) {
            throw new SearchEngineUnavailableException('The search engine did not answer.', 0, $exception);
        }
    }
}
