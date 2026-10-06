# How a "For you" run works

This page explains what happens when the reader generates your "For you"
recommendations, from the moment you press the button to the finished list.

## Pressing "Refresh"

Pressing **Refresh** in the "For you" list header, and confirming, starts a *run*
on the server. A run reads
your recent articles, sends them to the AI model you configured in
**Settings → AI**, and turns the model's answers into your "For you" list.
The run belongs to the server, not to your browser tab: the tab only watches
progress.

## You can close the browser

Once a run has started, the calculation keeps going server-side and
finishes on its own. You do not have to keep the tab open, keep the screen
on, or stay on the page.

When you come back, the reader shows whatever is current: the run's live
progress if it is still working, or the finished "For you" list if it
completed while you were away. One small caveat: a run that finishes while
the tab is closed shows no notification toast when you return — the result
is simply there.

## How fast it runs

Speed depends on how the server drives the run:

- **Fast:** while a background worker or an on-demand drainer process is
  active, the run advances continuously at full speed and typically
  finishes in one go.
- **Slower fallback:** on a host that cannot spawn a background process,
  the run advances one step per scheduled maintenance ping, so it moves at
  whatever interval the server is set to. It still finishes on its own — it
  just takes longer.

The reader picks the fast path automatically whenever the host allows it;
there is nothing to configure in the UI.

## Stopping a run

**Stop** ends the active run. Stopping is not instant: a request to the AI
provider that is already in flight finishes first, which is why the button
reads *Stopping…* until the run has ended. A stopped run keeps the
recommendations it had already banked from completed batches.

## When a run fails

A run fails when the AI provider stays unreachable, rejects your
credentials, or the account's AI configuration is removed mid-run. A run that needs your
reading profile while none is stored waits for one to be built ("Building your profile…"); when that fails, the
run fails with `Profile generation failed: …` and the reason the profile could not be built. A failed
run shows the reason in the "For you" view, and the next **Refresh** asks whether
to:

- **Resume unfinished run** — continue the failed run at the exact batch where
  it failed, keeping the work that already succeeded; or
- **Start a new run** — begin fresh with the newest articles.

Resume reuses the article snapshot from when the run first started, so if a
lot of time has passed, starting fresh gives more current recommendations.
A run that failed before it took its snapshot — a failed profile is one such case — has nothing to resume, and
neither has a run on a scoring model that started without a profile; **Refresh** then simply starts a new run.

## Install-dependent behavior

How the fast path is provided depends on the deployment:

- **Docker installs** run a persistent worker container that drives every
  run; nothing else is needed. (Operators: watching and restarting the worker
  is covered in [docker-production.md](docker-production.md) §9.)
- **Worker-less installs** (for example, shared hosting such as Strato)
  rely on an on-demand drainer process that the server starts when your run
  begins, backed by a scheduled maintenance ping as the safety net. If the
  host cannot start processes at all, the ping alone carries the run at the
  slower pace described above.

## For developers

### Engines

A run does not know which engine scores it. `RecommendationEngineResolver` is the one place that maps a connection to
an engine: the kind the connection stored when its model was chosen (`user_ai_settings.model_kind`, `llm` or `scoring`, as the model catalog tagged the model; the model id is never read); `SnapshotPhase` asks that engine to pack the candidate pool
into batches, and `TickPhases` hands it every later tick of a running run. The lock, the deferral after a rate limit,
the transport-failure strikes, cancelling and finalising stay with the run and are the same for every engine.

A rejected request is not a strike. When the provider answers that the request itself is wrong (`RejectingStatus`: any
4xx except 401 and 403, which mean the credentials, 408 and 425, which are about timing and still strike, and 429, which
is retried or deferred), the same request would earn the same answer, so the run fails on that first answer, with no
retry and no strike spent, and its error carries the provider's reason (`ProviderRejectedRequestException`). The call's
run-log row still reads `transport-failed`, with the reason in its error detail. This holds for both engines and for
profile runs.

One rejection is absorbed instead (`SuppressedReasoningFallback`): a 400 or 422 to a request that asked the model not to
reason ("Ask the model not to reason" on), on an engine that offers that setting, so never a scoring model. The run does not fail
and spends no strike; the connection records the refusing model (`user_ai_settings.suppression_refused_by_model`), and
the next tick asks the same model again without the field (`Reasoning::preferredBy()` answers `Allowed`). That costs one
rejected tick (a whole wave on a parallel run), once per model. The mark holds only while that model is chosen, shows a
note under the setting in Settings → AI, and is forgotten when the setting is toggled or the endpoint or key is
replaced. A rejection of the resend without the field is an ordinary rejected request and fails the run at once.

When a provider answers with an error status, the message in the run and its log carries the provider's own reason if
it gave one: the LLM engine for any non-retryable, non-credential error status, the scoring engine for every rejecting
status.

The LLM engine (`Service/Recommendation/Llm`) packs by the connection's context window, then scores the batches in
waves and consolidates the best of them into the final list with reasons. Neither engine builds the interest profile
(see [The profile](#the-profile)). Each engine kind
declares the phases it runs; a run records the kind it was packed for, and its progress (`batchesTotal`) and the
time-left estimate follow that kind's phases, learning only from completed runs of the same kind; the estimate also
adds those runs' median time between calls (worker pickup, tick waits), since elapsed counts from the run's
creation. A distillation is no longer a phase of a run, so the estimate learns only from runs on the current phases:
after the deploy that took distillation out of runs (#1351), the first estimates appear once one such run completes. The batch-wave skeleton (`BatchWavePhase`) loads each wave's batches, and `BatchWaveRounds` runs the wave's
rounds: an unusable batch retries alone, a deferring 429 settles every call, and one transport failure settles the
whole round unbanked (the atomic-wave rule). They, the rate-limit loop (`Ai\RateLimitedCalls`) and the run-log
recorder are shared, so an engine supplies only its `BatchWaveEngineInterface`: it opens, sends and judges its calls. Each kind also declares its capabilities (`reasons`, `prompt`, and which tuning fields it reads); the API passes them to the client,
which shows only the settings that apply. A connection's model list (`GET /api/me/ai/configs/{id}/models`, and
`models` in the answer to `POST /api/me/ai/configs`) carries, per model, the capabilities it would give, its
`kind` (`llm` or `scoring`) and its `family` (`decision` for System One, `reranker` for Rerank, `null` for an LLM); a
configuration carries the `kind` of its saved model. The picker asks for the kind first, lists only that kind's models
and tags each scoring model by its family, so no client parses an id:

```json
{"models": [{"id": "~typesafe/jev-latest", "kind": "scoring", "family": "decision",
  "capabilities": {"reasons": false, "prompt": false, "profile": "borrowed", "tuningFields": ["batchConcurrency"]}}]}
```

The scoring engine (`Service/Recommendation/Scoring`) scores every candidate against the reader instead of generating
text. It speaks to the model through a protocol (`ScoringProtocol/ScoringProtocolInterface`, one implementation per
`App\Enum\ScoringProtocol` case, found by `ScoringProtocolResolver`): the protocol packs the pool, words each request
and reads each reply as a value in [0, 1] per article. A connection stores the protocol beside the kind when its model
is chosen (`user_ai_settings.scoring_protocol`). The model catalog says what each model is: `OpenAiCompatibleCatalog` reads `GET {base}/models?output_modalities=all`; an entry whose `architecture.output_modalities` holds `text`, or that reports none (LM Studio, Ollama, OpenAI), is an LLM; `["decisions"]` is a System One model and `["rerank"]` a Rerank model, each listed only with a positive window (`context_length`, else `max_context_length`; its requests are budgeted from it, which leaves out Respan's `span-01*`); every other output (embeddings, image alone, …) is left out, while a model that outputs text beside images is an LLM. `SystemOneCatalog` probes `{base}/systemone` and offers `jev-latest` (32k) only when no listing named a System One model (TypeSafe direct, whose `/models` is not OpenAI-shaped); OpenRouter lists Jev itself as `~typesafe/jev-latest`.

| Protocol | Request | Per request |
|---|---|---|
| System One (`system_one`) | `POST {base}/systemone`, directly or through OpenRouter: `state` = `{profile, guidance?, favorites?}`, one `noul` question per article | at most 64 questions (Cloudflare's Clef refuses more); of the model's stored window, 2k for framing, `min(10k, 30 %)` for the state and the rest for questions (`SystemOneProtocol::budget()`, `ScoringBudgetModel::forWindow()`) |
| Rerank (`rerank`) | `POST {base}/rerank` through OpenRouter: `query` = the question, the guidance and the profile; `documents` = one line per article ("title — feed, date. description"); no `top_n`. Results come back sorted by relevance and are mapped to articles by `index` | at most 100 documents (Cohere bills 2–3 search units for such a request, since the query counts toward each unit); the query gets `min(10k, 30 %)` of the model's stored window, and each document what is left after that and 2k of framing — the window bounds each document, not their sum (`RerankProtocol::budget()`) |

A run freezes the stored profile when it snapshots; a scoring model needs one and fails with a message that says so when
there is none (an account with neither reading history nor a saved search). The LLM engine scores without a profile in
that case. The value is the score (× 1000): a decision model's probability, or a reranker's relevance, which only ranks
the articles of one run against each other (the for-you feed orders by run, then position, and never compares scores
across runs); there are no reasons and no consolidation, so the list is the best-scored picks once every batch is in.
The engine reads only the batch-concurrency setting and records each call's request id, answering model and cost in the
run log. `ScoringHttpTransport` sends a protocol's requests (its own idle and wall-clock timeouts, the tick heartbeat, a
1 MiB reply cap) and maps the statuses every protocol shares; a protocol adds only its retryable statuses (System One
and Rerank: 429 and 529). A run records the engine kind it was packed for; a tick that finds the
active connection on the other kind fails the run, and switching back resumes it. Any other change of model while a
run is pending or running is not guarded: the run carries on with the model the connection now holds.

### The profile

The interest profile is generated by its own runs (`ProfileRun`, `Service/Recommendation/Profile`), not by
recommendation runs. A profile run loads the reading history (favourites; kept and viewed up to the profile section's
caps) and the saved-search terms (the terms, not their results; a phrase in quotes, newest saved first, which the prompt
weighs with the favourites), fingerprints it together with the caps and the connection and model, and makes one LLM call
through the profile connection (Settings → AI; the active connection when none is chosen; a connection on a scoring model can never be
one). A usable reply replaces the stored profile (`user_recommendation_settings.profile_text` with its time, host and
model); three unusable replies or three transport failures fail the run and leave the stored profile alone, and so
does one rejected request (see [Engines](#engines)), which spends no strike. Neither history nor a saved search
completes the run (`no_history`) without a call. A scheduled run whose fingerprint equals the newest completed run's
completes as `unchanged` without a call; "Generate now" always calls the model.

A recommendation run never builds a profile. At its snapshot it asks the profile module (`ProfileForRunInterface`) for
one: the stored profile is frozen into the run; with none stored, a profile run is started and the run stays `pending`
until it ends — completed, the run freezes whatever is stored (possibly nothing); failed, the run fails with
`Profile generation failed: …`. The status JSON carries `waitingForProfile` meanwhile, and the For You toast says
"Building your profile…". A run that failed before its snapshot, or a run on a scoring model that froze no profile, is not resumable;
a new run asks again. The status JSON says so in `resumable`, which the client reads instead of guessing, so it never
offers a resume the server would refuse.

A waiting run is call-free: whatever drives the profile run (the worker, a drainer, the cron sweep) also lets the
waiting run go on. A host with none of them never builds the profile, and the run stays `pending` until it is stopped.

### The tick lock

Every tick runs behind a per-user lock (`UserTickLock::nameFor()`), so two drivers never advance
the same account's run at once. Profile runs take the same per-user lock (`UserTickLock`), so a profile tick and a
recommendation tick never overlap; each sizes the TTL by the connection it calls. `RecommendationRun` has no optimistic-version guard: a second tick that took the lock
mid-call could bank the same batch twice and pay for its provider call twice.

The lock's TTL does not cover a whole tick. `TickLockKeepalive` refreshes the lock on streamed chunks, at most once
every 30 seconds, so the TTL only has to outlast the longest stretch in which a live holder produces no chunk:

- a provider that sends nothing until the connection's first-byte timeout
  (`ProviderTimeoutsModel::$firstByteSeconds`: 180 s standard, 900 s on the slow profile);
- candidate loading and prompt assembly before the first request;
- ranking, banking and recording between calls and waves, and the whole snapshot tick.

The TTL is therefore the connection's first-byte timeout plus `TickLockTtl::MARGIN_SECONDS`
(300 s): 8 minutes on a standard connection, 20 on a slow one. Only a slow connection pays the longer TTL. Do not size
it from the keepalive's 30-second interval: that interval is a ceiling on refreshes, not a promise of one, and a live
slow-profile holder's lock would lapse mid-call for a second tick to take. Do not size it for the longest tick either
(`RecommendationRun::MAX_ATTEMPTS` rounds of a one-hour call, about three hours on the slow profile): a worker that
dies mid-tick would strand the run for that long, while a dead holder that stops refreshing now releases the run
within one TTL.

A request the gateway kills (Strato caps a web request at 240 s) never reaches the advancer's `finally`, so a shutdown
hook releases the lock as well. The keepalive is disarmed before the release, so no beat refreshes a lock on its way
out.

When a refresh fails because another process now holds the lock, `TickLockKeepalive::beat()` does not throw: it runs
inside the streaming loop, in the middle of an HTTP call, with nowhere safe to unwind. It records the loss, and
`RecommendationTickCheckpoint` stops the tick after the call and before anything is written to the run. A refresh the
lock store could not answer says nothing about the owner, so that tick continues. The two cases log different
messages, because a taken lock means the run is being advanced twice.

### Worker presence

`WorkerPresence` keeps one heartbeat row per `RecommendationDriverKind`: the persistent worker, an on-demand drainer,
and the cron sweep. A driver marks its row before each run and, through `SweepStreamHeartbeat`, on streamed chunks
during a provider call (at most once every 30 seconds). A browser poll tick is not a driver kind and marks nothing:
claiming liveness for it would stop every other tab from driving its own run.

A row counts as fresh for `WorkerPresence::FRESH_SECONDS` (960 s). The window is sized against the longest gap between
two touches, not against the sweep cadence. Chunks keep a streaming call's row fresh however long the call runs, so
the gap that remains is the silence before the first chunk: the slow profile's first-byte timeout of 900 s, plus time
for the next sweep and its bookkeeping. Raise `FRESH_SECONDS` whenever that timeout rises. Do not tie it to a call's
wall clock, which is an hour on the slow profile.

A window shorter than one unit of work would hand a run to the poll path while the worker is still working on it:
every poll would find the per-user lock held and log a lock with no heartbeat behind it. The wide window has a cost: a
worker that dies mid-call is recognised as gone up to 960 s later. That is the better failure, because a late
fallback resumes a run and a premature one fights a live worker for it.

A one-pass driver (a drainer, a cron sweep) surrenders its row when it ends, both in a `finally` and in a shutdown
hook, so the poll path and the drain spawner do not defer to a process that is gone. The persistent worker never
surrenders its row: it stops touching it, and the row ages out.

### Poll arbitration

`RecommendationPollDriver` decides what a poll does. While any driver's heartbeat is fresh, that driver owns
execution, and the poll only reports the run's state (`background` in the report). With nobody driving, the poll
ticks the run itself. If a driver dies mid-run, the next poll advances from the checkpoint. No setting switches
between the two modes.

The heartbeat is a hint; the per-user lock is the truth. A poll that ticks and finds the lock held gets a `busy`
result. It is answered like a fresh heartbeat (background, and the client keeps watching), and it also carries
`waitingForLock` and logs a warning with the lock's name: the lock is held, but nobody claims to be driving. The
advancer's own failed acquire stays silent, because down there a held lock is ordinary.

The flag does not mean the holder is dead. Two healthy cases also raise it:

- A second tab of the same account. A poll tick is not a driver kind, so two tabs take turns, and the one that loses
  the race reports a lock nobody has vouched for.
- Two cron passes at once. `/maintenance/tick` takes no lock over its sweep half, so overlapping passes share the one
  cron-sweep key. The first to finish surrenders it while the other still drives, and a poll that lands before the
  survivor's next mark finds the lock held with nothing behind it.

Telling a live holder from a dead one would need a second liveness subsystem, which two spurious warnings do not
justify. The flag and the log line are worded so that neither is false in these cases.
