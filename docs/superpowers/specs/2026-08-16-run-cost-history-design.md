# Record what each recommendation run costs, and show a run history (#409)

## Problem

Every recommendation call is billed and nothing in the app knows the price.

OpenRouter returns a `usage` object — `prompt_tokens`, `completion_tokens`, `cost`,
plus cached and reasoning details — in the last SSE message of a streamed reply.
`CompletionBodyDecoder` reads `choices[0]` only, and that final message carries
`choices: []`, so the whole object is discarded.

Nothing persists a price either. `recommendation_run_log` records `wire_bytes`,
`verdict` and `finish_reason`; `recommendation_run` records `attempts`,
`batches_done` and `transport_failures`. Wire bytes are not a cost proxy —
reasoning bytes and SSE framing inflate them. The log rows exist only while the
debug switch is on, and only for the last `DebugLogRetention::RUNS` runs.

## Goal

Capture the provider's own usage accounting on every call, keep per-run totals
independently of the debug switch and of the debug log's retention window, and
show a run history in AI settings: one row per run — time, provider/model,
duration, tokens, cost — with the all-time total at the top.

## Design

### 1. `CompletionUsage`, a new value object

`backend/src/Service/Recommendation/CompletionUsage.php`, `final readonly`:

```php
public function __construct(
    public int $promptTokens,
    public int $completionTokens,
    public int $reasoningTokens,
    public int $cachedTokens,
    public ?int $costNanoCredits,
) {}
```

Cost is **nano-credits as an integer**. It is money, so no floats. Null means the
provider reported no price — the same answer as "local model, free". A provider
that reports tokens but no cost yields tokens with a null cost, not a zero cost:
zero would claim the run was free, which is a different statement from "unpriced".

Nano-credits fit a 64-bit int with room to spare (1 credit = 1e9), so the column
is `BIGINT` and the PHP type stays `int`. DBAL 4's `BigIntType` returns `int` for
any value inside PHP's integer range.

### 2. Decoding

`CompletionBodyDecoder` gains one public method, `usage(string $json): ?CompletionUsage`,
and `streamEvent()` gains a `usage` key in its returned shape. The decoder is
already the class that "knows where a /chat/completions answer sits inside the
provider's JSON"; usage sits at the payload root rather than inside a choice, so
the shared `firstChoice()` walk is split into `decodeRoot()` + `firstChoiceIn()`
and both readers work off one decode. Decoding once per event is what #327 bought
and must not be given back.

Field mapping, every step guarded because the provider is untrusted:

| Wire | Value object |
|---|---|
| `usage.prompt_tokens` | `promptTokens` |
| `usage.completion_tokens` | `completionTokens` |
| `usage.completion_tokens_details.reasoning_tokens` | `reasoningTokens` |
| `usage.prompt_tokens_details.cached_tokens` | `cachedTokens` |
| `usage.cost` (float credits) | `costNanoCredits = (int) round($cost * 1e9)` |

A `usage` member that is not an array, or absent, yields null. Missing token
counts read 0; a missing or non-numeric `cost` reads null.

### 3. The value rides on `CompletionStreamProgress`

`CompletionStreamProgress` gains a fourth constructor parameter,
`?CompletionUsage $usage = null`. This is the codebase's stated fix for tramp
data — the value reaches `RecordedCall` on the object that already travels there,
not as a new parameter threaded through `ChatCompletionClient`, the advancer and
the wave. `phptramp` stays quiet.

`CompletionStreamReader` holds the usage as sticky state, exactly as it holds
`finishReason`: once an event carries it, it stays, so a later event without it
cannot erase it. For a provider that ignores `stream: true`, `usage()` reads the
blocking envelope through the decoder, the same two-shape split the reader
already makes for content and reasoning.

The reader does **not** salvage a usage event left unterminated in `pendingLine`.
Doing so would mean a JSON decode per chunk on a partially-buffered event, which
is the parse cost #327 removed. Real SSE terminates its frames, and OpenRouter
sends `data: [DONE]` after the usage message, so the message is always followed
by more bytes.

`OpenAiCompatibleChatClient::consumeChunk()` passes `$reader->usage()` into the
progress it already constructs. No new call is added — the usage message arrives
as body content, so the observer is already notified for that chunk.

### 4. `stream_options: {include_usage: true}`

Sent unconditionally in `completionPayload()`. It is OpenAI spec, not a vendor
extension — unlike the `reasoning` member, which is per-connection for exactly
that reason. OpenRouter documents it as inert; a plain OpenAI-compatible endpoint
sends no usage without it.

### 5. `RecordedCall` banks at settle

`RecordedCall` holds the usage from every progress report, sticky, exactly as it
already holds `finishReason`, and banks it when the call settles — in `finish()`
and in `abortAfterTransportFailure()`, **before** the `$logId === null` guard.
That is what makes the totals debug-independent: the RecordedCall exists whether
or not the debug switch is on.

Banking is DBAL with SQL arithmetic:

```sql
UPDATE recommendation_run
   SET prompt_tokens = prompt_tokens + :prompt, ...
 WHERE id = :id
```

so concurrent calls of a #344 wave cannot lose each other's increments and the
advancer's EntityManager is not flushed mid-tick. Cost is banked by a second
statement, run only when the provider reported one:

```sql
UPDATE recommendation_run
   SET cost_nano_credits = COALESCE(cost_nano_credits, 0) + :cost
 WHERE id = :id
```

An unpriced run therefore keeps `NULL` rather than being coerced to 0.

Banking is guarded by a `$usageBanked` flag: one provider call is billed once,
however many settle paths reach it. The flag is per-`RecordedCall`, so a retry
and the discarded sibling of an aborted wave — separate instances — are each
banked. #344 already documents that re-bill as accepted; the history is where it
becomes visible.

A failed call is banked too. The provider billed it.

### 6. The run carries provider, model and totals

New columns on `recommendation_run`:

| Column | Type | Note |
|---|---|---|
| `provider_host` | `VARCHAR(255)`, null | host of the connection's base URL |
| `model` | `VARCHAR(255)`, null | model id as configured at run time |
| `prompt_tokens` | `INT`, default 0 | |
| `completion_tokens` | `INT`, default 0 | |
| `reasoning_tokens` | `INT`, default 0 | |
| `cached_tokens` | `INT`, default 0 | |
| `cost_nano_credits` | `BIGINT`, null | null = provider reported no price |

`provider_host` and `model` are stamped by `RecommendationRunStarter` at start and
re-stamped on resume. Copied onto the run rather than read back through the
account's current configuration: the configuration is editable, and a history that
renames last month's runs when the model changes is not a history. Only the host
is kept, not the whole base URL — the host is what identifies the provider, and a
path adds nothing a history row can use.

The entity exposes `stampProvider(?string $providerHost, ?string $model): void`
and read accessors. It never mutates the totals: those belong to `RecordedCall`'s
DBAL writes, and a setter would invite a second, racing writer.

### 7. `GET /api/recommendations/runs/history`

A new controller, `RecommendationRunHistoryController`, alongside
`RecommendationDebugLogController`: `#[CurrentUser] User`, ownership enforced in
the repository, no `#[IsGranted]`, no rate limiter — the same shape and the same
reasoning as the debug log endpoint.

```json
{
  "runs": [
    {
      "id": 42,
      "status": "completed",
      "providerHost": "openrouter.ai",
      "model": "x-ai/grok-4-fast",
      "createdAt": "2026-08-16T09:12:00+00:00",
      "completedAt": "2026-08-16T09:12:47+00:00",
      "durationSeconds": 47,
      "promptTokens": 118432,
      "completionTokens": 2216,
      "reasoningTokens": 0,
      "cachedTokens": 0,
      "costNanoCredits": 41230000
    }
  ],
  "totalCostNanoCredits": 918200000
}
```

The newest 50 runs. The total is a database `SUM` over **every** run of the user,
not a sum over the returned page — a total that silently means "of the last
fifty" is a wrong number, not a cheaper one. A user with no priced run at all
gets `null`, not `0`.

`durationSeconds` is computed server-side from `createdAt`/`completedAt`, the same
rule `RecommendationRunStatusJson` follows: the client never subtracts timestamps
across machines. Null while the run has not finished.

Assembly lives in `src/Http/RecommendationRunHistoryJson.php` and the query in
`RecommendationRunRepository` (`findNewestForUser` already exists; add
`historyForUser(User, int $limit)` returning scalar rows and
`totalCostNanoCredits(User)`).

### 8. The settings card

`frontend/src/app/settings/recommendation-run-history.component.{ts,html,scss}`,
mounted in `ai-section.component.html` inside `@if (activeReady())`, above the
debug log, with no debug gate.

- Total cost at the top of the card.
- One row per run: date and time, `providerHost · model`, duration, tokens, cost.
- Cost renders as credits with four decimals (`nano / 1e9`), and `—` when the
  provider reported none.
- Dates through `formatDateOr`/`formatTime` — `DatePipe` renders `en-US` whatever
  the language is.
- `status` renders the raw API wire vocabulary untranslated, the rule the debug
  log records.
- Fetched once on init and re-fetched when `RecommendationsService.completedStamp()`
  changes; no poll loop — a finished run is the only thing that changes this list.

New i18n keys under `settings.ai.recommendations.history*` in both
`public/i18n/en.json` and `de.json`.

## Out of scope

Existing runs cannot be retro-filled. The OpenRouter generation ids were never
stored, so `/api/v1/generation` cannot recover their cost. They read `—`.

Per-call usage is not added to `recommendation_run_log`. The run is where the
spending record has to survive the debug switch and the retention window, and a
second copy on a row that is deleted after ten runs would only be able to
disagree with it.

## Verification bar

Gates green is not the deliverable. This ships only after, with the debug switch
**off**, a live OpenRouter run completes and the history card shows that run with
a non-empty cost and a total that grew by it — and after an LM Studio run shows
tokens with `—` for cost.
