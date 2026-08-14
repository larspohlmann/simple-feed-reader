# Recommendation look-back window — design

Issue: [#386](https://github.com/larspohlmann/simple-feed-reader/issues/386)
Branch: `feature/386-recommendation-lookback-window`
Date: 2026-08-14

## Problem

The recommendation candidate pool is bounded only by a count.
`RecommendationCandidateLoader::load()` takes the newest `candidatePoolSize`
unread entries (default 500) with no time bound. On busy feeds that reaches back
a few hours; on quiet feeds it reaches back months. The reader cannot say "only
look at the last N days".

The single knob that exists, `Candidate pool size`, sits in **Expert settings**
and its name describes the implementation, not what it does for the reader.

## Solution

1. A **look-back** dropdown in the normal recommendation settings, outside
   Expert settings: **1–7 days, default 2**. No "unlimited" option.
2. The existing `candidatePoolSize` becomes the hard cap on articles **inside**
   that window, relabelled `Maximum articles` (English) / `Maximal Artikel`
   (German). The translation key and the field's place in Expert settings do not
   change.

Selection order is unchanged: newest first inside the window, cut at the cap,
then the seeded shuffle from #344.

## Decisions

- **Default 2 days for everyone.** This changes results for existing accounts on
  purpose. No per-user grandfathering of the old unbounded behaviour.
- **Rolling window.** `since = now − N × 24 h`, computed in UTC at snapshot time.
  Not calendar days: a calendar boundary makes the window width vary between
  N−1 and N days depending on the run time, and UTC midnight is not the reader's
  midnight.
- **The cap stays in Expert settings.** The dropdown alone is the everyday
  control.
- **The window is a query input, not run state.** Nothing about it is stored on
  the run.

## Settings and persistence

`lookbackDays` joins the settings chain as a plain `int` override, in every place
the other caps already live:

| File | Change |
|---|---|
| `Service/Recommendation/RecommendationSettingsValues.php` | new `int $lookbackDays` |
| `Service/Recommendation/EffectiveRecommendationSettings.php` | new field, `DEFAULT_LOOKBACK_DAYS = 2` |
| `Service/Recommendation/RecommendationSettingsResolver.php` | resolve row value against the default |
| `Service/Recommendation/RecommendationSettingsWriter.php` | carry through `withNormalisedGuidance()` |
| `Entity/RecommendationSettings.php` | column with `['default' => DEFAULT_LOOKBACK_DAYS]`, plus `update()` and `values()` |
| `Dto/Recommendation/SaveRecommendationSettingsRequest.php` | `#[Assert\Range(min: 1, max: 7)]` |
| `Http/RecommendationSettingsJson.php` | `'lookbackDays' => $effective->lookbackDays` |

Migration: adds `user_recommendation_settings.lookback_days INT NOT NULL
DEFAULT 2`, in the shape of `Version20260814140000` — platform-aware MySQL and
SQLite branches, `abortIf` on any other platform, a column-exists guard, and
`isTransactional(): false`. Existing rows take the default.

`RecommendationSettingsValues` and `SaveRecommendationSettingsRequest` already
carry ten constructor arguments and already suppress
`PHPMD.ExcessiveParameterList`. An eleventh keeps both 1:1 mirrors of the
settings row, which is the reason those suppressions exist. Splitting them is
out of scope here.

## The query

New value object `Service/Recommendation/CandidatePoolRequest.php`, a
`final readonly class` with promoted constructor properties:

- `\DateTimeImmutable $since` — the window boundary, already resolved
- `int $poolSize` — the hard cap inside the window
- `int $orderSeed` — the #344 shuffle seed

`RecommendationCandidateLoader::load(int $userId, CandidatePoolRequest $request)`
replaces the three-scalar signature. A fourth scalar parameter would break the
standing parameter-count rule and start exactly the tramp-data shape phptramp
gates; the value object follows the recorded pattern (`Service/Fetch/PageUrls.php`,
`Service/Scraper/JsonLdArticles.php`). Passing `EffectiveRecommendationSettings`
instead was rejected: a query object has no business reading the history caps
and packing settings, and the order seed is not a setting.

The added predicate:

```php
->andWhere('e.effectiveDate >= :since')
->setParameter('since', $request->since)
```

`orderBy('e.effectiveDate', 'DESC')`, `addOrderBy('e.id', 'DESC')`,
`setMaxResults($request->poolSize)` and the seeded shuffle are unchanged, so the
cap keeps meaning "newest N inside the window".

`linesForIds()` and `summarize()` are untouched. `CandidatePoolSummary` needs no
change; its oldest/newest dates now describe the window, which is what the
prompt's pool-frame line should say.

## The run snapshot

`RecommendationRunAdvancer::snapshotTick()` builds the request:

```php
$now = $this->clock->now();
$request = new CandidatePoolRequest(
    since: $now->sub(new \DateInterval(\sprintf('P%dD', $effectiveSettings->lookbackDays))),
    poolSize: $effectiveSettings->candidatePoolSize,
    orderSeed: (int) $now->getTimestamp(),
);
```

The clock is already injected there and already supplies the seed. Datetimes are
stored as naive UTC and `ClockInterface` yields UTC, so no conversion is needed —
this is stated explicitly because a non-UTC value reaching a datetime column is
this project's recurring trap.

The window is frozen for free. `snapshotTick()` runs once and freezes entry ids
into the batch plan; every later tick, including a resume, re-resolves those ids
through `linesForIds()`, which never sees the window.

A window that matches nothing needs no new failure path: the existing empty-pool
branch freezes an empty plan and completes the run. "For you" keeps its earlier
items, because `RecommendationItemRepository::listForYou()` reads the items of
all completed runs, not just the latest.

## Frontend

API shape gains one field in each direction: `lookbackDays` in the `GET` state
and in the `POST` body. It is a required `int`, not nullable — unlike
`contextWindow` and `batchCount` there is no "automatic" meaning to express. No
new endpoint, so the native-client checklist is unaffected.

- `recommendation-settings-card.component.html` — a `<select>` directly below the
  auto-generate field and above the `no-worker` note, outside the Expert
  disclosure. It copies the auto-generate pattern: `@for` over the options with
  `[selected]`, a `setLookbackDays($event)` handler, `data-testid="lookback-days"`.
- `recommendation-settings-card.component.ts` — a `lookbackOptions` array of seven
  `{ value, key }` entries and
  `linkedSignal<number>(() => this.svc.state()?.lookbackDays ?? 2)`. The class
  docstring's "six numeric tuning fields" sentence is corrected rather than left
  stale.
- `recommendation-settings.service.ts` — the field in the state and save-payload
  interfaces, and in the `save()` payload.
- `public/i18n/en.json`, `public/i18n/de.json` — a `lookback` label and
  `lookback1`…`lookback7` options ("Last 24 hours", "Last 2 days", … /
  "Letzte 24 Stunden", "Letzte 2 Tage", …). The `candidatePool` key keeps its
  name; only its text changes to "Maximum articles" / "Maximal Artikel".

No new SCSS: the field sits in the card's default flow like auto-generate, so
there is no hex colour and no raw `px` for Stylelint to reject.

## Tests

Backend:

- `RecommendationCandidateLoaderTest` — one entry inside the window and one just
  outside it; the outside one is absent. The cap still truncates inside the
  window. An entry whose `effectiveDate` equals `since` is included, asserted
  explicitly so the mutant flipping `>=` to `>` dies.
- `RecommendationRunAdvancerTest` — with a frozen clock, the snapshot uses
  `now − lookbackDays` from the effective settings; a window matching nothing
  still completes the run with an empty plan.
- Settings round-trip — resolver default of 2 with no row, writer and entity
  persistence, `SaveRecommendationSettingsRequest` rejecting 0 and 8, and the
  field present in the settings JSON.

Frontend — `recommendation-settings-card.component.spec.ts`: the select renders
the saved value, changing it puts `lookbackDays` in the save payload, and the
existing `'Candidate pool size'` expectation moves to `'Maximum articles'`.

## Verification

`composer check`, `composer md`, `php bin/phpunit` natively and in Docker,
`composer infection:diff`, PhpStorm inspections on the changed PHP, and
`npm run check`. The migration is verified on its own: migrate from empty on
SQLite and on MySQL in a **scratch** database, then `doctrine:schema:validate`.
Never against the dev database.

## Out of scope

- Moving the article cap out of Expert settings.
- An "unlimited" look-back option.
- Grandfathering existing accounts onto the old unbounded pool.
- Any change to how `linesForIds()` resolves a resumed batch.
