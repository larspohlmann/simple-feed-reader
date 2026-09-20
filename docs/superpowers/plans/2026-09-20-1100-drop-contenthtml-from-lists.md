# Plan: #1100 — drop contentHtml from list responses; server excerpt; body on open

Spec: GitHub issue #1100 (binding authority). Maps (verify before relying):
`.superpowers/sdd/2026-09-20-1100-drop-contenthtml-from-lists/{backend,frontend}-map.md`.

## Context

Every list endpoint returns the full `contentHtml` of all 100 rows (60–76% of the
payload; `?tag=92` is 1.9 MB). The list barely uses it. Replace it with a plain-text
`excerpt` per row; keep `contentHtml` only on `GET /api/entries/{id}`; the feed-mode
reader view fetches the body on open and prefetches neighbours; the client image
fallback (first `<img>` of the body) is removed.

## Global Constraints

- No list response (`/api/entries`, `/api/entries/search`, `/api/entries/saved-searches`,
  the for-you feed, AND the nested `duplicates`) contains `contentHtml`. `GET /api/entries/{id}`
  keeps it.
- Every list row carries `excerpt: string` — plain text, whitespace-collapsed, at most
  500 chars cut at a WORD BOUNDARY. Source: the summary's text, else the body's text; a
  body/summary that is only a serialised null yields `''`. The NULL_LEAK rule moves
  server-side (one copy). `summary` stays in the list row unchanged.
- `excerpt` is COMPUTED per request by a small pure service — not persisted, no migration,
  no backfill, no backup-format change.
- The repository keeps selecting the full `Entry` entity (no partial objects / body table).
- Native-iOS: list + stateless per-entry fetch, no browser-only input — compliant.
- House style both sides (see repo CLAUDE.md): backend `final`, strict_types, guard
  clauses, no restating comments, PHPMD-clean, ThinController; frontend standalone+signals,
  no NgModules, component styles in sibling `.scss`, no `contentHtml` DOMParser per card.
- Gates: `composer check` + `composer md` + PhpStorm clean + `infection:diff` 100% (backend);
  `npm run check` (ESLint+Prettier+Stylelint+Jest) green (frontend, in the Docker container).

## Task 1 — Backend: split the mapper, add `excerpt`

1. **Excerpt service.** A small pure `final` service (e.g. `App\Http\EntryExcerpt` or
   `App\Service\Text\EntryExcerpt`) with a method `of(Entry $entry): string` (or
   `from(?string $summary, ?string $contentHtml): string`). Rule: prefer the summary's
   plain text, else the body's plain text; return `''` for a null-leak. REUSE
   `App\Service\Text\PlainText::fromHtmlBlocks()` for HTML→text and the null-leak idea from
   `App\Service\Ingest\EntrySnippet`; reconcile the junk list to the UNION of the frontend
   (`none|null|undefined`) and server (`none|null|nil|n/a|-|—`) lists — one definition.
   Truncate to ≤500 chars **at a word boundary** (not a raw `mb_substr` mid-word).
   `summary` is already `EntrySnippet`-clean and ≤500, so the summary branch is usually a
   passthrough; the body branch does the strip+collapse+cut. Multibyte-safe (`mb_*`).
2. **Split `EntryJson`.** Give it a list-row shape and a detail shape. Options: two static
   methods (`EntryJson::listRow(EntryListRow): array` dropping `contentHtml` and adding
   `excerpt`, recursing `duplicates` through `listRow`; `EntryJson::detail(EntryListRow): array`
   = list row + `contentHtml`), sharing one private field-assembly for the common fields.
   Inject/obtain the excerpt service (EntryJson is static today — if it must call a service,
   pass the excerpt in, or make the excerpt computable from the entity via a static helper;
   pick the cleanest that keeps EntryJson's call sites simple and stays PHPMD/ThinController
   clean). The detail row's `duplicates` also use the LIST shape (a duplicate never needs a body).
3. **Repoint call sites** (backend-map §"Call sites"): `EntryPage::withMatchCount` (:74),
   `RecommendationFeedJson::page` → the list shape; `EntryController::get` (:110) → the detail
   shape. Search/saved-search inherit via `EntryPage`.
4. **Update the `EntryJson` PHPDoc** (the authoritative shape doc) to describe both shapes and
   the new `excerpt`; note the naming distinction from `ReaderJson`'s extraction `excerpt`.
5. **Tests.**
   - Excerpt unit tests: summary present (passthrough), summary empty + body (body text),
     both empty → `''`, null-leak body/summary (`None`/`null`/`undefined`) → `''`, entity
     decoding, >500 cut at a word boundary, multibyte text.
   - Functional (both legs): `/api/entries`, `/api/entries/search`, `/api/entries/saved-searches`,
     the for-you feed, and the nested `duplicates` carry NO `contentHtml` and DO carry `excerpt`;
     `GET /api/entries/{id}` carries `contentHtml`. Use the existing controller-test fixture
     builders.
6. Verify: `composer cs && composer stan && composer md` clean; full `php bin/phpunit`; MySQL
   leg for the changed tests; `composer infection:diff` 100%; PhpStorm clean. Re-run cs +
   affected tests after EVERY edit.

### Task 1 interface for Task 2 (record in the ledger after Task 1)
- Exact list-row JSON field set (esp. that `contentHtml` is gone and `excerpt` is present).
- Exact `GET /api/entries/{id}` response shape (the entry object still nests under `entry`
  and now uniquely carries `contentHtml`).

## Task 2 — Frontend: excerpt, body store, body-on-open, prefetch

Depends on Task 1's wire shape (use the recorded interface).

1. **Types** (`models.ts`): `EntryDto` drops `contentHtml`, gains `excerpt: string`. Add
   `EntryDetailDto` (= `EntryDto & { contentHtml: string | null }`) for the `/entries/{id}`
   response; `ReaderApi.entry(id)` returns `{ entry: EntryDetailDto }`.
2. **Snippet**: `entrySnippet(entry)` returns `entry.excerpt`; delete `textSnippet`, the
   `snippets` WeakMap, `NULL_LEAK`. `entry-quote.component.ts` `lead` uses `entry.excerpt`
   (keep the first-sentence slice). `QUOTE_MIN_TEXT=300` keeps working.
3. **Image**: delete the `firstPreviewImage(entry.contentHtml, …)` fallback in
   `resolveEntryImage`; `entryImage()` reads only `imageUrl`/`imageWidth`/`imageHeight`.
   Remove `firstPreviewImage`/`pickImage` if now unused; delete the archive-rationale doc
   comment (don't narrate the change).
4. **Body store** — a new in-memory singleton service: `body(id)` returning the body (signal
   /resource) backed by `ReaderApi.entry(id)` (reads `.entry.contentHtml`). In-memory `Map`
   keyed by entry id, LRU cap 50. De-dup in-flight requests per id. Id-guard: a late response
   for an id no longer open must not write into the view (reuse the `reader-shell` pattern).
   Cleared on logout via `onIdentityChange` in the constructor (see frontend-map). NOT
   persisted; `reader-cache.service.ts` untouched.
5. **Feed-mode open** (`reader-view.component.ts` `displayHtml` :325): render title/hero/meta/
   `summary` at once from the list row; the body (from the store) replaces the summary when it
   arrives — no spinner over existing content, no jump beyond the body growing. On failure:
   keep the summary and show an inline error with a retry action, built from the shared
   components in docs/design-language.md. Reader mode (`a.contentHtml`) unchanged.
6. **Prefetch**: when an entry opens, prefetch the previous and next entry of the current list
   order (index in `EntriesStore.entries` vs the open `entryId`). No hover prefetch, none for
   the whole page. (NOTE: no j/k or entry-swipe navigation exists yet — prefetch still warms
   the store for re-opening an adjacent entry; do NOT build j/k/swipe navigation.)
7. **Deep links**: keep using `GET /api/entries/{id}`; they now also seed the body store with
   the detail's `contentHtml`.
8. **Tests** (Jest in the Docker frontend container): body store — cache hit, in-flight
   de-dup, LRU eviction, stale-response guard, cleared on logout; reader-view feed mode —
   summary first, body on arrival, error + retry; prefetch of neighbours on open; the ~23
   specs' list-row fixtures drop `contentHtml` and use `excerpt`; behavioral specs
   (`preview-image.spec.ts`, `entry-quote`, `magazine-planner`, `reader-view`, `reader-shell`)
   updated. e2e: specs opening an entry stub their own `/api/entries/{id}` body (each spec owns
   its data); a Playwright smoke: open an entry in feed mode → body appears.
9. Verify: `npm run check` green in the Docker frontend container. Re-run after every edit.

## Pre-merge production gate (DECISION REQUIRED — do NOT merge without resolving)

Removing the client image fallback means the ~209 entries whose card image came only from
a body `<img>` lose it (47 are a tracking pixel). Before merge, run on PRODUCTION:
```sql
SELECT f.title, COUNT(*), MIN(e.created_at), MAX(e.created_at)
FROM entry e JOIN feed f ON f.id = e.feed_id
WHERE e.image_url IS NULL AND e.content_html REGEXP '<img[^>]+src=["\'']https://'
GROUP BY f.id ORDER BY 2 DESC;
```
If it shows pre-image-column archive rows (old `created_at`, feeds other than the three the
issue names), backfill their `image_url` through the ingest image selector FIRST. This needs
production DB access — surface it to the user; do not touch production autonomously.

## Out of scope
Dropping `summary` from list rows; persisting `excerpt`; partial entity selection / body
table; changing which images ingest accepts; building j/k/swipe entry navigation.

## Acceptance
- No list response contains `contentHtml`; `?tag=92` drops ~1.9 MB → < 500 KB uncompressed.
- Cards show the same snippet text as before for entries with a summary, body-derived text
  otherwise.
- Feed-mode reader view shows the full body; neighbour navigation is instant (prefetch).
- No `DOMParser` per list card remains.
