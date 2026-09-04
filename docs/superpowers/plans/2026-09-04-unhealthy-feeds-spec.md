# Unhealthy feeds in settings — spec (#847)

Source: an interview (grilling) session, 2026-09-04. This spec is the agreed
design; the implementation plan argues from it.

## Goal

Show the user, inside settings, which of their feeds are unhealthy, and let them
act on each one — retry the fetch, or unsubscribe.

## Definitions

- A feed's health is the shared `FeedStatus` enum: `active`, `erroring`, `gone`.
- **Unhealthy** = `erroring` or `gone`. Pills: `erroring` → "Failing"; `gone` → "Dead".
- Feed health is global (one `Feed` row per URL). The list is filtered to the
  current user's subscriptions.
- There is no stale/quiet-feed signal and no malformed-parse state; a parse
  failure just increments the failure streak. Those are out of scope.

## Placement

- A group at the top of the Organise page (`/settings/organise`).
- The group renders only when the user has at least one unhealthy feed; when all
  feeds are healthy there is no trace of it.

## The row (slim, purpose-built — NOT the full Organise row)

- Favicon, title, one status pill, one friendly reason line.
- Two controls: **Retry** and **Unsubscribe**.
- Friendly reason line, derived on the client:
  - `gone` → "No longer available".
  - `erroring` with a known last success → "No update in N days" (whole days
    since `lastSuccessfulFetchAt`, floored; 0 days → "No update since today").
  - `erroring` that never succeeded → "N failed attempts" (`consecutiveFailures`).
- Order: one list, `gone` first, then `erroring`; within each, by title.

## Details on click

- Clicking the row body toggles an inline `app-disclosure`.
- Details show: last successful update, last attempt, failure streak, and the
  raw `lastErrorMessage` as monospace diagnostic text marked technical.
- The Retry and Unsubscribe controls stop the click; they act, they do not expand.

## Retry

- Runs the manual single-feed refresh: `POST /api/refresh?feedId=`.
- That path reaches `gone` feeds too (schedule, `status != gone`, and the
  per-feed cooldown are all bypassed; ownership is enforced). On success
  `recordSuccess` resurrects the feed to `active`.
- No "retry all". Per-row only. Rationale: the refresh rate limit is per-user and
  global (90 / 5 min), so a one-click retry-all is how a user burns the budget.
- The client awaits the result, then reloads subscriptions. Recovery = the
  refresh reported no failure and at least one feed fetched or not-modified. On
  recovery: toast success; the row leaves the list; the badge count drops. Still
  broken: toast failure; the row stays with its reason refreshed.

## Discoverability

- A count badge on the Organise nav entry (rail and hub), showing the number of
  unhealthy feeds, computed client-side from the loaded subscriptions. No new
  endpoint. Hidden when the count is 0.

## Backend change (the only one)

Serialize three already-persisted fields onto `SubscriptionJson::one`:
`lastSuccessfulFetchAt` (ISO 8601 or null), `consecutiveFailures` (int),
`lastErrorMessage` (string or null). `status` and `lastFetchedAt` already ship.
Additive; safe for the native iOS client.

## Out of scope (v1)

Stale/quiet detection, malformed-parse as a distinct state, a separate "Feed
health" settings section, a reader banner, bulk retry-all.
