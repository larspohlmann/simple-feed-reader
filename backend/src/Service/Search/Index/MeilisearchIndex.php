<?php

declare(strict_types=1);

namespace App\Service\Search\Index;

use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\SearchEngineCapability;
use App\Service\Search\SearchTerms;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The one class in this codebase that knows Meilisearch's wire format. Talks
 * to it as a plain JSON API over HttpClientInterface (see
 * Service/Recommendation/OpenAiCompatibleChatClient for the same pattern
 * against a different engine) rather than the vendor SDK, so the whole surface
 * this class needs — five endpoints — stays inspectable and testable with
 * MockHttpClient instead of pulled in behind a PSR-18 client the SDK would
 * discover on its own.
 *
 * Every write Meilisearch accepts answers 202 with an enqueued task and does
 * the actual indexing afterwards (confirmed by probe, see
 * `.superpowers/sdd/2026-08-16-432-meilisearch-search/task-2-report.md` and
 * its `wire-format-addendum.md`) — this class deliberately does NOT poll
 * `GET /tasks/{taskUid}` for any of them.
 * SearchIndexWriter's methods return void precisely because nothing here
 * reports back whether the write has actually landed: an ingest-time call
 * (EntryIndexer, Task 6) is a side effect of storing an entry that must never
 * turn a refresh into a poll loop over the search engine's queue, and it
 * already has a durable source of truth to repair from —
 * `app:search:reindex` — if the write is ever lost. A caller that genuinely
 * needs to know a rebuild finished (the reindex command itself) is exactly
 * that repair command, which this task does not build; it can poll the task
 * queue directly if it ever needs to, without this adapter's ingest-time
 * callers paying for it.
 */
final readonly class MeilisearchIndex implements SearchIndexReader, SearchIndexWriter
{
    private const string INDEX = 'entries';

    /**
     * The engine is a container on the same network as this process, not a
     * remote provider: an answer here is never more than a fast local
     * round-trip away, and the database is always a fallback away too, so a
     * hung request must fail fast rather than hold a user's search open.
     */
    private const float TIMEOUT_SECONDS = 3.0;

    /**
     * Sentinel highlight delimiters, not `<mark>`/`</mark>`: an article's own
     * text can legitimately contain a literal "<mark>" (a copy-pasted HTML
     * snippet in a feed body, for instance), and if it did, highlightedWordsIn()
     * would either miss a real match or invent one from someone else's markup.
     * These bracket-and-colon sequences are not producible by anything Meilisearch
     * indexes as searchable text (title/summary are plain text — see
     * IndexedEntry) and are cheap to prove absent: PlainText::from() runs
     * strip_tags() before either field reaches this class, so no upstream
     * value can carry them in either. Distinct open/close strings (rather than
     * a single symmetric tag) let the extraction regex require both ends
     * before it treats anything as a match.
     */
    private const string HIGHLIGHT_START = '[[sfr:hl]]';
    private const string HIGHLIGHT_END = '[[/sfr:hl]]';

    /**
     * The wire shape measured against the running engine — see
     * `.superpowers/sdd/2026-08-16-432-meilisearch-search/task-2-report.md`
     * and its `wire-format-addendum.md` for the probed requests and
     * responses this is built from.
     *
     * `searchableAttributes` covers every field #432 asks to be searchable —
     * title, summary, the plain-text content, and the feed title — which is
     * the ticket's full-content-matching goal: a word appearing only in an
     * article body must find something, not nothing.
     *
     * The ORDER of that list is a behavioural contract, not cosmetic:
     * Meilisearch's attribute ranking rule ranks a hit by which attribute in
     * this list it matched, in the order declared here. Title before summary
     * before content before feed title is deliberate — a match in the
     * headline should outrank the same word buried in the body or riding in
     * on the feed's own name.
     *
     * filterable/sortable list precisely the fields IndexSearch's cursor and
     * feed scoping use.
     *
     * @var array{
     *     searchableAttributes: list<string>,
     *     filterableAttributes: list<string>,
     *     sortableAttributes: list<string>,
     * }
     */
    private const array SETTINGS = [
        'searchableAttributes' => ['title', 'summary', 'content', 'feedTitle'],
        'filterableAttributes' => ['feedId', 'effectiveDate', 'id'],
        'sortableAttributes' => ['effectiveDate', 'id'],
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private SearchEngineCapability $capability,
    ) {
    }

    public function find(IndexSearch $search): IndexMatches
    {
        $body = $this->requestBody('POST', '/indexes/' . self::INDEX . '/search', [
            'json' => $this->searchPayload($search),
        ]);

        return $this->matchesFromResponse($body);
    }

    public function configure(): void
    {
        $this->requestBody('PATCH', '/indexes/' . self::INDEX . '/settings', [
            'json' => self::SETTINGS,
        ]);
    }

    public function upsert(array $entries): void
    {
        if ([] === $entries) {
            return;
        }

        // The primary key can never be inferred (see the class docblock's
        // linked probe): every document below carries both `id` and `feedId`,
        // two field names ending in "id", which is exactly the ambiguity
        // Meilisearch refuses to guess through.
        $this->requestBody('POST', '/indexes/' . self::INDEX . '/documents?primaryKey=id', [
            'json' => array_map($this->documentOf(...), $entries),
        ]);
    }

    public function forget(array $entryIds): void
    {
        if ([] === $entryIds) {
            return;
        }

        $this->requestBody('POST', '/indexes/' . self::INDEX . '/documents/delete-batch', [
            'json' => $entryIds,
        ]);
    }

    public function clear(): void
    {
        $this->requestBody('DELETE', '/indexes/' . self::INDEX . '/documents');
    }

    /**
     * @return array<string, mixed>
     */
    private function searchPayload(IndexSearch $search): array
    {
        return [
            'q' => $this->queryStringFor($search->terms),
            'filter' => $this->filterFor($search),
            // Newest first, ties broken by id — the same order EntryRepository
            // uses for every other list, so a page hydrated from these ids
            // (IndexedEntrySearch) matches what the caller already expects.
            'sort' => ['effectiveDate:desc', 'id:desc'],
            // Every term must match somewhere in the document. The default
            // ("last") silently drops trailing terms until something matches,
            // which would turn a two-word search that matches nothing into a
            // one-word search that matches everything — a worse answer than
            // reporting no results (confirmed by probe).
            'matchingStrategy' => 'all',
            'limit' => $search->limit,
            // title/summary are highlighted without being requested here: the
            // probe confirmed `_formatted` carries them from
            // attributesToHighlight alone, so retrieving anything beyond the
            // id this class actually uses would be dead weight on every reply.
            'attributesToRetrieve' => ['id'],
            'attributesToHighlight' => ['title', 'summary'],
            'highlightPreTag' => self::HIGHLIGHT_START,
            'highlightPostTag' => self::HIGHLIGHT_END,
        ];
    }

    /**
     * The `q` string for one search. A whole-word search — the trailing space
     * the user typed, carried here as SearchTerms::$isWholeWord — becomes one
     * quoted phrase per term: a phrase in Meilisearch's query language matches
     * the word exactly, where a bare term also matches by prefix and by typo.
     * That is why a whole-word search for "punk" was answering with
     * "Pünktlichkeit" until #450; probed against v1.13, which narrowed that
     * same search from 82 hits to 16.
     */
    private function queryStringFor(SearchTerms $terms): string
    {
        $words = array_map(self::withoutPhraseDelimiters(...), $terms->terms);

        if (!$terms->isWholeWord) {
            return implode(' ', $words);
        }

        return implode(' ', array_map(static fn (string $word): string => '"' . $word . '"', $words));
    }

    /**
     * A double quote opens or closes a phrase in Meilisearch's query language,
     * so one arriving inside a term would close a whole-word phrase early and
     * leave the next one hanging open. It becomes a space — which is what the
     * LIKE engine's WordBoundaries already does with it, so a quote inside a
     * term reads as a word boundary on both engines rather than as a character
     * to match.
     */
    private static function withoutPhraseDelimiters(string $term): string
    {
        return str_replace('"', ' ', $term);
    }

    private function filterFor(IndexSearch $search): string
    {
        $filter = sprintf('feedId IN [%s]', implode(',', $search->feedIds));

        if (null === $search->cursor) {
            return $filter;
        }

        // Keyset pagination on (effectiveDate, id) descending: everything
        // strictly before the cursor's date, plus same-date rows with a
        // smaller id — the compound predicate the probe confirmed Meilisearch
        // accepts verbatim as a plain filter string.
        return $filter . sprintf(
            ' AND (effectiveDate < %1$d OR (effectiveDate = %1$d AND id < %2$d))',
            $search->cursor->effectiveDate->getTimestamp(),
            $search->cursor->id,
        );
    }

    private function matchesFromResponse(string $body): IndexMatches
    {
        $decoded = json_decode($body, true);
        if (!\is_array($decoded) || !isset($decoded['hits']) || !\is_array($decoded['hits'])) {
            throw new SearchEngineUnavailableException('The search engine answered with an unreadable response.');
        }

        /** @var list<array<mixed>> $hits */
        $hits = array_values(array_filter($decoded['hits'], static fn (mixed $hit): bool => \is_array($hit)));

        return new IndexMatches($this->entryIdsOf($hits), $this->matchedWordsOf($hits));
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
        // Non-greedy capture between the sentinel pair: greedy would span from
        // the first open tag to the LAST close tag in the field, swallowing
        // any unhighlighted text between two separate matches into one
        // "word".
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
    private function documentOf(IndexedEntry $entry): array
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
     * The shared core of every call: send, read the whole body (a
     * short-lived local response, never worth streaming), and turn a
     * transport failure or a non-2xx status into the one exception every
     * caller already knows how to handle.
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
        } catch (ExceptionInterface $e) {
            throw new SearchEngineUnavailableException('The search engine did not answer.', 0, $e);
        }
    }
}
