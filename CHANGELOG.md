# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Version sections below are generated automatically when a release tag is pushed;
see [docs/releasing.md](docs/releasing.md). History before the first release
lives in the git log and the merged pull requests.

## [Unreleased]

## [v0.6.0] - 2026-08-19

### Highlights

**AI recommendations (For You).** A per-account "For You" feed that ranks your
unread entries with an AI provider of your choice. Save one or more provider
profiles and switch between them, run a ranking on demand or on a per-user
schedule, and follow it with a live run view, an ETA, and cost bounds. A
background worker and a detached drainer carry long runs to completion, and
results are scored so the strongest articles surface first.

**Full-text search.** Search across your entries, backed by Meilisearch with a
permanent database fallback so search keeps working even when the index is
unavailable. You choose whether to include Meilisearch at install time — the
database fallback covers the rest. Whole-word matching is supported, and results
reload in place without blanking the list.

**Account backup and restore.** Export a whole account — feeds, tags, read
state, and your kept and favorited articles — and restore it into another
instance. A self-hosted install accepts a real account backup, so you can move
between instances or keep an off-site copy.

**Simplified install and update.** The production installer sets up the full
stack from a single package question and survives a second run, and
`scripts/update.sh` moves an existing install to the latest release. Both
resolve the latest release straight from git, with no dependency on the GitHub
Release object.

## What's Changed
* Per-account AI provider configuration (groundwork) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/306
* Record actively opened entries as viewed (#307) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/313
* feat(#308): AI-powered For you recommendation feed by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/314
* feat(#311): background worker container for recommendation runs, feed refresh and housekeeping by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/315
* Score-based recommendation ranking (#316) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/317
* Stream provider responses in the AI client for early stall detection by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/318
* Realtime debug view for recommendation runs (#309) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/319
* Stop charging reasoning tokens to the answer's budget (#320) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/322
* For-you polish: layout, controls, cost bounds (#321) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/324
* Move the For You run trigger into the list title bar by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/326
* fix(#327): reasoning models fail ranking — max_tokens starves the answer by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/328
* fix(#329): LM Studio json_schema, real transport errors, batch degrade, resume-or-fresh choice by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/330
* For You: show the recommendation strip in every reading layout (#331) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/332
* feat(#333): scheduled auto-generation of For You (per-user cadence + external-cron endpoint) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/335
* Save multiple AI provider configurations and switch the active one (#334) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/337
* fix(#323): recover the recommendation answer from reasoning_content when content is empty by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/339
* For-You run: ETA + anticipatory progress bar (#336) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/340
* feat(#323): per-config toggle to ask the provider not to reason by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/341
* fix(#342): mobile layout regressions + settings polish by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/343
* Parallel recommendation batch calls + recommendation-quality tuning (#344) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/345
* feat(#346): single /maintenance/tick endpoint (refresh + recommendations) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/349
* feat: duplicate an AI provider configuration + collapsible AI settings UI (#347) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/350
* For You: run-boundary headers between recommendation runs (#348) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/351
* refactor: collapsible settings-card composes app-disclosure (#352) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/353
* fix(#354): pin every resolved IP so the fetch client can fall back across families by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/355
* fix(#356): cross-family fetch failover on a dead-route reset by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/357
* fix(#358): cross-family failover on a route-specific error status (taz.de) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/359
* fix(#360): force a fresh connection on a failover retry (taz.de) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/361
* fix(#362): exclude the worker from the e2e stack boot by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/363
* fix(#364): tighten adaptive refresh bounds (floor 5 min, ceiling 6 h) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/365
* feat(#366): sort entry list by refresh run, not article publication time by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/367
* fix(#368): read Atom <dc:date> so tagesschau & NDR entries get a published date by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/369
* fix(e2e): let the seeded admin accept a scraped candidate by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/377
* refactor: give the page URL a home in the scraper and discovery by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/375
* build: gate the backend on phptramp by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/379
* build: run the gate against phptramp's latest develop by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/381
* fix(security): php_codesniffer 3.13.6 closes CVE-2026-67434 by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/383
* fix(#388): follow phptramp to Packagist by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/389
* fix(#384): sort on a clamped effective date, purge by fetch date by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/387
* feat(#386): recommendation look-back window (1-7 days, default 2) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/391
* Spawn a detached CLI drainer to drive recommendation runs to completion (#371) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/392
* feat(#372): in-page documentation for the AI settings by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/395
* fix(#396): reject a dedup reply that names most of the list by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/397
* fix(#399): stop the batch prompt asking for candidates to be left out by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/400
* feat(#401): keep the debug log of the last ten runs by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/402
* feat(#403): score on 0-1000, and ask for exact values by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/404
* feat(#406): dedup lines carry the description, not the reason by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/407
* Search entries by title and summary by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/413
* Show the entry actions on every magazine card by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/418
* fix(#415): report a rejected AI configuration where it happened, with the server's reason by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/420
* feat(#398): show For-You progress in the app-wide pill, not only the reader header by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/421
* fix(#423): parse feeds that start after the XML declaration by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/425
* fix(#424): report a bot gate as a refusal, not as a missing feed by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/426
* docs(#428): target the README at end users, polish the operator guides by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/429
* feat(#430): print the setup information last, and default to port 3333 by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/431
* feat(#409): record what each recommendation run costs, and show a month-paged run history by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/427
* Per-connection AI timeout profile, and the info tip that opened in the wrong place by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/434
* Design polish sweep by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/436
* fix(#437): bound a runaway completion where the runaway is by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/438
* fix(#441): clean up the #437 runaway bounds, and close what review found by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/442
* Meilisearch-backed entry search with a permanent database fallback (#432) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/446
* fix(#448): let a search reload the list without dimming or veiling it by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/449
* fix(#450): carry the whole-word mode into the search index query by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/451
* Bound a stranded recommendation run to minutes, split slow_model, move the drainer spawn by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/452
* docs(#432): move Meilisearch wire-format evidence out of scratch by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/447
* Back up and restore a whole account between instances (#412) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/457
* fix(#458): let a self-hosted install accept a real account backup by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/459
* fix(#462): fade a list's rows as soon as they render by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/463
* feat(#453): one package question, with the memory each one needs by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/464
* fix(#465): fit the run-history row on a phone by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/466
* fix(#467): keep the image when a page lazy-loads it by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/469
* Reader header: kept/favorite on mobile, per-article refresh, shared loading overlay (#470) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/471
* Box publisher inserts as cards, strip orphaned icon glyphs (#472) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/474
* fix(#468): halve the article reading-focus change from #435 by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/477
* feat(#478): add "Recently read" saved view by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/479
* fix(#476): reader extracts the wrong block — extract both ways, keep the richer by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/481
* feat(#482): tick=viewed, circle=read; enforce viewed⊆read by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/485
* Visual refinements: AI settings hint, mobile search, sidebar chevrons, entry rows (#486) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/487


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.5.6...v0.6.0

## [v0.5.6] - 2026-08-06

## What's Changed
* fix(#302): stop the refresh poll loop on a rationed feed by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/303


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.5.5...v0.5.6

## [v0.5.5] - 2026-08-06

## What's Changed
* fix(#274): measure the magazine kicker after the layout settles by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/279
* fix(#280): never gate boot on the i18n dictionary fetch by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/281
* fix(#282): boot watchdog reveals the error surface when nothing renders by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/284
* fix(#285): a navigation watchdog for stalls the router cannot report by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/287
* fix(#286): show a clicked list from the top by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/288
* fix(#283): find the feed a page hides — conventional paths and feed-shaped links by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/289
* fix(#290): fill a new feed from the document discovery already read, and stop treating 429 as a failure by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/291
* fix(#292): let an unbreakable word wrap, once, for every surface by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/293
* feat(#294): show the tag's glyph and colour in the list header by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/295
* fix(#296): trim the blank tail a feed leaves on an article by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/297
* Add Infection mutation testing to the quality pipeline by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/299
* refactor(#300): drop DatabaseClock, the timezone pin from #154 covers it by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/301


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.5.4...v0.5.5

## [v0.5.4] - 2026-08-04

## What's Changed
* fix(#275): stop docker compose from eating a piped installer by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/276
* feat(#277): let the installer choose SQLite instead of MySQL by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/278


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.5.3...v0.5.4

## [v0.5.3] - 2026-08-04

## What's Changed
* fix(#250, #260): clear the PhpStorm ERROR findings in backend/src and backend/tests by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/268
* fix(#267): give each list its own scroll position on a view switch by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/269
* feat(#270): keep the article named while it scrolls by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/271
* feat(#272): a production installer that survives a second install, and sets up the catalog it ships by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/273


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.5.2...v0.5.3

## [v0.5.2] - 2026-08-03

## What's Changed
* feat(#237): make website scraping an opt-in experimental preference by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/239
* feat(#238): a reading-progress bar for the article view by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/240
* feat(#241): an All Items pill in the mobile tag row by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/242
* fix(#141): cache-bust the Transloco dictionaries with the release version by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/243
* fix(#119): tell the user when a refresh accomplished nothing by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/244
* fix(#245): materialize entry.effective_date and index the list sort by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/248
* feat(#246): delete a user account with all of its content, and reclaim orphaned feeds by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/249
* fix(#247): show the account status after an OAuth sign-in by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/251
* feat(#252): split the installer's public-URL question into three by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/253
* Run both e2e suites weekly in CI by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/258
* Cover frontend/e2e with the Prettier check by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/259
* fix(#255): drop the host from the outbound User-Agent by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/261
* fix(#254): compress responses and keep the list rendered while it reloads by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/262
* fix(#263): void per-user caches when the signed-in identity changes by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/264
* chore: back-merge main into develop before cutting v0.5.2 by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/265


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.5.1...v0.5.2

### Changed

- The production installer asks how users reach the instance, under which
  hostname, and on which port, instead of one "Public URL" question that
  carried all three. The new first question offers plain HTTP, a certificate
  this stack serves, or a reverse proxy in front — and the proxy answer now
  writes the loopback bind address and the moved TLS port itself, instead of
  leaving them as a hand edit ([#252](https://github.com/larspohlmann/simple-feed-reader/issues/252)).

### Fixed

- A port equal to the scheme's default no longer reaches `PUBLIC_URL`. The
  resulting `https://host:443/...` did not match an OAuth redirect URI
  registered as `https://host/...`, which providers compare exactly
  ([#252](https://github.com/larspohlmann/simple-feed-reader/issues/252)).
- Answering the public-origin question with a malformed value asks again
  instead of aborting the installer after the clone
  ([#252](https://github.com/larspohlmann/simple-feed-reader/issues/252)).

## [v0.5.1] - 2026-08-02

## What's Changed
* feat(#230,#224): mailless-capable instance + registration-gate toggles by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/231
* fix(#213): stronger reading focus, no scroll lag by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/232
* fix(#212): keep long tag names inside the mobile viewport by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/233
* fix(#212): keep settings/tags rows on one line on mobile by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/234
* Reader extraction fixes and typographic variety by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/236


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.5.0...v0.5.1
