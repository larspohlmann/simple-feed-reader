# Scoring models from more providers — design

Issue #1394. Status: approved in conversation with Lars on 2026-10-05; this document records it.

## Goal

Jev (#1345) was the first model of a category: a model that **scores** each candidate article against the reader
profile instead of generating text. Since Jev launched (2026-09-15), about a dozen providers have shipped models of
the same category. Make "scoring model" one recommendation engine with a **protocol seam** beneath it, so that:

- another model on a protocol we already speak needs no code at all (the catalog lists it), and
- another protocol is one adapter class plus one enum case.

The model picker in Settings → AI asks **"LLM or scoring model?"** first and then lists only that kind's models.

## Research and spike findings

Web research 2026-10-05, then a spike of real calls through OpenRouter with the dev DB's connection 8 (key opened
in-process, never printed; total spend under 0.01 USD; throwaway script deleted, no tracked file or DB row changed).

**Two scoring families exist; both are on OpenRouter.**

| Family | Wire | Models verified with a real call |
|---|---|---|
| Decision model ("System One") | `POST {base}/systemone` (OpenRouter also `/api/alpha/decisions`): `{model, state, questions{id:{type:'noul', instructions}}}` → `{answers{id:{noul}}, usage, id, model}` | `~typesafe/jev-latest`, `perplexity/pplx-decider-v1-27b`, `cloudflare/clef-flash`, `togethercomputer/tev1-4b-experimental`, `jaredpalmer/kev-4b`, `inception/mercury-decide:free`, `liquid/d1`, `upstage/solar-decide` — all answer Jev's exact shape |
| Reranker | `POST {base}/rerank`: `{model, query, documents[]}` → `{results[{index, relevance_score}], usage, id, model}` | `cohere/rerank-4-fast`, `cohere/rerank-v3.5`, `voyageai/rerank-3`, `qwen/qwen3-reranker-8b`, `nvidia/llama-nemotron-rerank-vl-1b-v2:free` |

Findings the design depends on:

1. **The catalog says what a model is.** `GET /models?output_modalities=all` returns every model with
   `architecture.output_modalities`: `["text"]`, `["decisions"]`, `["rerank"]`, or something we do not use (`image`,
   `embeddings`, `video`, `transcription`, `speech`). The plain `GET /models` returns text models only, which is why
   OpenRouter's decision models and rerankers are invisible to the current `OpenAiCompatibleCatalog`.
2. **The catalog does not say how many items a request may carry.** Neither `/models` nor
   `/models/{id}/endpoints` has a per-request item limit. Measured: Clef refuses 70 questions with 422 ("at most 64
   items"); Jev accepted 130; Perplexity documents 128. Cohere bills a "search unit" per up to 100 documents.
3. **Respan `span-01*` is listed as a decision model but is not one for us:** it refuses a profile state (400,
   "state must be … a message array"). Its catalog entry is the only decision model with `context_length: 0`.
4. **Reranker scores are comparable across batches of one run.** Same query, same document, different batch:
   Cohere 0.8740 vs 0.8715, Voyage 0.6523 vs 0.6523. Ranking across batches needs no normalisation.
5. **Reranker results come back sorted by score, not in request order**; `index` maps them back.
6. **Reranker cost differs by provider**: Cohere bills per search (`usage.search_units`, `usage.cost` 0.001–0.002 USD),
   Voyage and Qwen per token (`usage.total_tokens`). Every reply carries `usage.cost`, which `ReportedCost` already reads.
7. **Errors:** OpenRouter answers a bad model with 400 `{"error":{"message":…,"code":400}}`, a bad key with 401, an
   upstream limit with 429 (Cloudflare and Perplexity both rate-limited within a few calls; no `retry-after` seen),
   and forwards an upstream validation failure as 422 with the upstream body nested in `error.message`.
8. **Reranker quality varies a lot** (NVIDIA's 1B ranked the pumpkin soup above the Postgres article; Cohere v3.5
   barely separated them). That is the user's choice of model, not something the app filters.

OpenAI's Decisions API (GPT-6 Luna, announced 2026-09-29) is invite-only with no public schema, endpoint or model id.
Anthropic, the Gemini API and xAI have no scoring endpoint.

## Scope

In:
- One `Scoring` engine kind; protocols **System One** and **Rerank**.
- Model sources: OpenRouter's catalog (decision models and rerankers), and TypeSafe direct (the existing probe).
- Kind-first model picker.

Out (each a follow-up when wanted):
- OpenAI's Decisions API — a third protocol once it is public and a real call can be made.
- Direct reranker providers (Cohere, Voyage, Jina) and local servers (vLLM, llama.cpp, Ollama's `/v1/systemone`):
  they speak the same wires but have no common way to list their models. Each is one catalog member later.
- One connection serving two models. A connection keeps one model; an OpenRouter user who wants an LLM profile
  connection and a scoring model adds the key twice, as today.
- The other System One question types (`choice`, `score`). We ask `noul` only.

## Design

### D1. One engine kind, one enum for connection and run

`App\Enum\RecommendationEngineKind` becomes `Llm | Scoring` (`'llm'`, `'scoring'`). It is both what a connection's model
drives and what a run records; there is no second "model kind" enum. `phases()` gives `Scoring` `[Batch]`, as Jev today.

### D2. The protocol is an enum beside it

`App\Enum\ScoringProtocol`: `SystemOne` (`'system_one'`), `Rerank` (`'rerank'`). In `App\Enum` because both the
connection and the run store it (architecture §8). `family()` names what the user sees: `SystemOne` → decision model,
`Rerank` → reranker.

### D3. The catalog tags every model

`ModelDescriptorModel` becomes `(id, contextWindow, kind, scoringProtocol)`. `scoringProtocol` is null exactly when
`kind` is `Llm`.

- `OpenAiCompatibleCatalog` requests `GET {base}/models?output_modalities=all` and maps each entry:
  - no `architecture.output_modalities` → `Llm` (LM Studio, Ollama, OpenAI and every gateway that does not report it,
    exactly as today);
  - `["text"]` → `Llm`; `["decisions"]` → `Scoring`/`SystemOne`; `["rerank"]` → `Scoring`/`Rerank`;
  - anything else → left out.
  - A `Scoring` entry without a positive context window is left out: the packer cannot budget it (this is what
    excludes Respan, finding 3, by a rule taken from the data rather than a name).
  - **Assumption (verify in PR 2):** OpenAI-compatible servers that do not know the parameter ignore it. Checked by a
    real call against the dev LM Studio connection; if one refuses, the catalog retries without the parameter.
- `SystemOneCatalog` (the `{base}/systemone` probe for TypeSafe direct) becomes a **fallback**: the composite consults
  it only when no other member reported a `SystemOne` model. Otherwise OpenRouter would list Jev twice
  (`~typesafe/jev-latest` from the listing, `jev-latest` from the probe). Its descriptor carries
  `Scoring`/`SystemOne` and its 32,000-token window.
- The `jev-` prefix rule (`RecommendationEngineResolver::kindForModel`, `JEV_MODEL_PREFIX`, `labelForModel`) is deleted.

### D4. The connection stores what the catalog said

`AiProviderSettings` gains `modelKind` (`RecommendationEngineKind`, nullable — null while no model is chosen) and
`scoringProtocol` (`ScoringProtocol`, nullable). `AiProviderConfigurator::chooseModel()` stores them from the descriptor
together with `model` and `modelContextWindow`, as it stores the window today. The resolver's `kindFor(connection)`
reads `modelKind ?? Llm`.

Migration: two nullable `VARCHAR(16)` columns; backfill `model LIKE 'jev-%'` → `scoring`/`system_one`, every other row
with a model → `llm`. Connection 8 (`jev-latest` on OpenRouter) keeps working unchanged; the picker will offer it as
`~typesafe/jev-latest` the next time the user re-chooses.

### D5. Per-request limits: the window is stored, the item cap belongs to the protocol

The item cap is not in any catalog (finding 2), so it is a protocol property, not a per-model one:
`SystemOne` → 64 questions (the smallest cap measured, Clef; every System One model accepts it), `Rerank` → 100
documents (one Cohere search unit). This replaces Jev's 100 — a deliberate change in PR 2, not PR 1; for Jev it means
more, smaller requests, each repeating the state (about 0.003 USD more per 1,000-candidate run at Jev's price).

The context window comes from the connection's stored `modelContextWindow`. The packer's budget scales with it instead
of the 32k constant: the state (System One) or query (Rerank) gets `min(10_000, 30 % of the window)` tokens, framing
keeps its 2,000, the questions or each document get the rest. At 8k (Kev) that is 2,400 state + 2,000 framing +
3,792 for questions, where today's constants would not fit at all.

### D6. Module layout

`Service/Recommendation/Jev` becomes `Service/Recommendation/Scoring` (one sub-module in `phpstan.dist.neon`).

Engine side, renamed neutrally from Jev's generic classes: `ScoringRecommendationEngine` (tagged item `'scoring'`),
`ScoringBatchPacker`, `ScoringBatchWave`, `Factory/ScoringStateFactory`, `ScoreParser`, `Support/ProbabilityScore`
(P × 1000, clamped), `Support/ScoringArticle`, `Support/FittingPrefix`, `Pass/ScoringWave`, `Pass/ResponseWave`,
`Model/ScoringOutcomeModel`, `Model/ScoringReplyModel` (`id → value`, body, receipt), `Model/ScoreParseResultModel`.

Protocol side: `ScoringProtocol/ScoringProtocolInterface` with `SystemOneProtocol` and `RerankProtocol` in the same
folder (architecture §10), indexed by `ScoringProtocol` value through a tagged locator inside the module. Their
wire helpers go to `Model/` and `Support/` (`SystemOneRequestModel`, `RerankRequestModel`, `SystemOneReplyDecoder`,
`RerankReplyDecoder`, `CompactJson`).

Shared HTTP: `HttpSystemOneClient`'s streaming, idle and wall timeouts, heartbeat and byte cap become one
`ScoringHttpTransport`. The status mapping both protocols share — 401/403 → `CredentialsRejectedException`,
`RejectingStatus` → `ProviderRejectedRequestException` with `ProviderErrorReason`, ≥300 → unreachable — lives there;
each protocol adds only its retryable statuses (`SystemOne`: 429, 529; `Rerank`: 429).

### D7. What a protocol supplies

```php
interface ScoringProtocolInterface
{
    /**
     * @param list<ArticleLineModel> $candidates
     *
     * @return list<list<int>> entry ids per request, in candidate order (the packing rule, D5)
     */
    public function pack(ScoringBudgetModel $budget, array $candidates): array;

    /**
     * @param list<ScoringRequestModel> $requests
     *
     * @return list<ScoringOutcomeModel> aligned by index; a failed call is an outcome, never a throw
     */
    public function scoreMany(ProviderCredentialsModel $credentials, array $requests): array;
}
```

`ScoringRequestModel` is neutral: model, profile, guidance, the articles. Each protocol encodes it:

- **System One:** state `{profile, guidance?}`, one `noul` question per article (today's request, byte for byte).
  Packing: the state once, the questions summed, at most 64.
- **Rerank:** `query` = profile + guidance + the question sentence, fitted to the query budget; `documents` = one compact
  line per article ("title — feed, date. description", `ScoringArticle`'s caps), each cut to what is left of the window
  after the query; no `top_n`. Packing: at most 100 documents; the window bounds each document, not their sum.

Each protocol decodes its reply to `article id → value in [0,1]` plus the receipt:

- System One: `answers[id].noul`; tokens `usage.input_tokens`/`output_tokens`; request id from the header, else body `id`.
- Rerank: `results[].relevance_score` mapped back by `index` (finding 5); tokens `usage.total_tokens` when present, else
  null; request id body `id`; answering model body `model`; cost `usage.cost` for both.

### D8. The run

- A migration rewrites `recommendation_run.engine_kind` `'jev'` → `'scoring'` and adds a nullable `scoring_protocol`
  column, backfilled `system_one` for those rows. The snapshot records the connection's protocol beside the kind.
- The engine-switch guard (`TickPhases`, D27 of #1345) fires when the connection's kind **or protocol** differs from
  the run's: one run never mixes calibrated probabilities with relative relevance. The run stays resumable.
- A model switch within one protocol is allowed, as for the LLM engine. Batches packed for the old window that no
  longer fit the new one are refused by the provider and fail the run as #1387 does.
- "Needs a profile" becomes a capability check: `RecommendationRun::isResumable()` and the engine's `NO_PROFILE` failure
  ask `RecommendationEngineCapabilitiesModel::of(kind)->profileSource === Borrowed`, not the Jev case; the failure text
  names no model.
- Capabilities: the `Scoring` arm is today's Jev arm (no reasons, no prompt, borrowed profile, batch-concurrency
  tuning), the same for both families.
- ETA and phase timing stay keyed by kind; decision-model and reranker runs share one timing history.

### D9. API and picker

- The model list (`/api/me/ai/configs/{id}/models`) and the configuration JSON gain `kind` (`'llm'|'scoring'`) and
  `family` (`'decision'|'reranker'|null`), plain camelCase (architecture §6; no new endpoint, nothing browser-only).
  `label` and `capabilities` stay.
- Settings → AI: a two-option control, **LLM** / **Scoring model**, above the model dropdown, preselected from the
  connection's current model. The dropdown lists only that kind. Scoring options carry a "Decision model" or
  "Reranker" tag; a hint under the control says a reranker's score only ranks articles within a run, a decision
  model's is a probability. An option the provider offers no models for is disabled with "This provider offers no
  scoring models" (or LLMs).
- The Jev-specific copy (`addIntro`, `modelPicker`, `guide.jev*`, `guide-jev`) becomes a general scoring-model guide,
  English and German.

### D10. Errors

No new exception types. Status mapping as D6; `ProviderErrorReason` reads `detail` (TypeSafe direct) and
`error.message` (OpenRouter, including the nested upstream body of finding 7), scrubbed and clipped as today.
A reply is **unusable** — retried up to `BatchWaveRounds::MAX_ATTEMPTS`, then the batch fails — when an article id is
missing, a rerank `index` is missing or repeated, or a value lies outside [0,1] (some local rerankers return raw
logits; the app does not guess a normalisation).

## Testing

- Per protocol: encoding, decoding, status mapping and packing against `MockHttpClient`, with the spike's real response
  bodies as fixtures (sanitised).
- Engine: against a stub protocol, so engine tests contain no wire JSON.
- Wiring: every `ScoringProtocol` case has a protocol implementation; every `RecommendationEngineKind` case has an
  engine; the resolver reads the stored kind. The `jev-` prefix tests are deleted.
- Catalog: the modality mapping table (text, decisions, rerank, image, missing architecture, zero window); the fallback
  rule (probe consulted only without a listed `SystemOne` model).
- PR 1 keeps every existing Jev assertion green under the new names: that is the proof System One is unchanged.
- Migrations: the CI migrate-from-empty leg on SQLite and MySQL, plus a backfill test (a `jev-latest` connection and a
  `jev` run come out `scoring`/`system_one`); then applied to the live Docker DB.
- Frontend: Jest in the container for the kind control, filtering, family tag and hint; one Playwright smoke with a
  stubbed model list.
- Gates: `composer check`, `composer md`, `composer infection:diff`, both PHPUnit legs, `npm run check`.
- After PR 2 and PR 3: one real run each on the dev stack (a decision model other than Jev; a reranker) through the
  app, not a script.

## Docs

- `docs/recommendations-runs.md`: the kind is stored on the connection; the protocol table; the item caps and budgets.
- `docs/architecture.md` §9: `Recommendation\Scoring` replaces `Recommendation\Jev`.

## Delivery

Umbrella #1394, one sub-issue and PR each, merged into `develop` in order:

1. **Refactor, no behaviour change** — `Jev` → `Scoring` with the protocol seam and `SystemOneProtocol` as its only
   implementation; `ScoringHttpTransport`; `RecommendationEngineKind::Scoring`; the connection and run columns with
   their backfills; the resolver reads the stored kind; the profile check by capability. Jev's item cap stays 100 and
   its window 32k here.
2. **Decision models** — kind-tagged catalog with the modality mapping and the fallback rule; per-protocol item caps
   and window-scaled budgets (D5); API `kind`/`family`; the kind-first picker and the general copy. Reranker entries
   stay left out of the catalog until PR 3 adds their protocol.
3. **Rerankers** — the `ScoringProtocol::Rerank` case, `RerankProtocol` and its decoder; the catalog maps `["rerank"]`;
   the reranker family in the picker.
