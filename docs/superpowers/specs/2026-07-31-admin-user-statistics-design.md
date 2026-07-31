# Per-user admin statistics — design

**Issue:** part of [#180](https://github.com/larspohlmann/simple-feed-reader/issues/180) Phase 5 (better user management)
**Related:** [#66](https://github.com/larspohlmann/simple-feed-reader/issues/66) (per-user limits: max feeds, trial expiry)
**Date:** 2026-07-31
**Scope:** Backend *and* frontend. Unlike the #180 phase-1 work, this adds a
database field, a migration and a new endpoint.

## Goal

Give an admin a real picture of each registered account: how recently the person
signed in, how much they have set up, and exactly which tags and feeds they own.
Counts stay on the users list; the full lists live on a new per-user detail page.

## 1. Surfaces and routing

The admin users list at `/settings/admin/users` stays the entry point. Each row
links to a new detail page.

| Route | Content | Guard |
|---|---|---|
| `/settings/admin/users` | List: identity, status, auth methods, **counts**, last login, actions | auth + admin |
| `/settings/admin/users/:id` | Detail: identity, activity, footprint, **full tag list**, **full feed list**, actions | auth + admin |

The detail route is a lazy child of the settings shell, declared in
`settings.routes.ts` with `adminGuard`, exactly like the existing admin pair.
The section config (`settings-sections.ts`) is **not** extended: the detail page
is a child of the users section, not a nav entry of its own.

## 2. What the list page shows

Each user row gains three at-a-glance figures beside the existing status badge
and auth-method list:

- **feeds** — number of subscriptions
- **tags** — number of tags
- **last login** — relative ("3 days ago"), or "never" when the account has
  never signed in

The existing approve / reject / suspend actions are unchanged, including the
two-step confirm for reject and suspend. The row links to the detail page.

## 3. What the detail page shows

**Identity and account.** Email, status badge, roles, auth methods (local
password, Google, Apple), locale, created date with age, approved date.

**Activity.** Last login. Last refresh — the newest `Feed.lastFetchedAt` across
the user's subscribed feeds. A **dormant** flag when the last login is older
than 90 days (or the account never signed in and was created more than 90 days
ago).

**Footprint.** Feeds used against the global cap
(`SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER`, currently 500). Tag count.
**Stale feeds** — how many of the user's feeds have not been fetched in 7 or
more days, which is the useful signal given refresh is manual (there is no
scheduler, by standing decision).

**Full tag list.** Every tag the user owns, in the user's own position order:
colour swatch, icon, name, and the number of the user's feeds carrying that tag.

**Full feed list.** Every subscription, in the user's own position order: feed
title (and the custom title when set), feed URL, the tags applied to it,
subscribed-on date, and a freshness indicator derived from `lastFetchedAt`.

**Actions.** Approve / reject / suspend, reusing the same two-step confirm as
the list page. A read-only "Limits" block shows max feeds and trial expiry as
"not set", reserving the layout for #66. This spec does **not** build limit
editing.

## 4. Backend

### 4.1 `lastLoginAt`

`User` gains a nullable `lastLoginAt` (`DATETIME_IMMUTABLE`) plus a migration.
It is stamped at **token issuance**, on both authentication paths:

- password login — `AuthController::login()`, at the point the JWT is created;
- OAuth — `OAuthSignIn::redeemLoginCode()`, where the JWT is created after the
  status gate passes (`src/Service/OAuth/OAuthSignIn.php:115`).

Stamping at issuance, not per request, keeps the write off the hot path and
keeps the stateless bearer-token contract intact. Datetimes are stored as naive
UTC, normalised before persisting, per the project's standing rule.

The migration must run from empty on **both** SQLite and MySQL — the test
bootstrap builds the schema from ORM metadata, so no test executes a migration.
The dedicated migration CI leg is the gate here.

### 4.2 Endpoints

`GET /api/admin/users` (existing) gains `feedsCount`, `tagsCount` and
`lastLoginAt` per user. These come from aggregate `COUNT(...) GROUP BY` queries
in the repository, keyed by user id — **not** per-row lookups. The list must not
regress into N+1.

`GET /api/admin/users/{id}` (new) returns the detail payload: identity, the
activity and footprint figures, `tags[]` and `subscriptions[]`. A missing id is
a 404 `application/problem+json`.

Response shapes are new, single-purpose DTOs under `src/Dto/Admin/`. Controllers
stay thin and delegate; the statistics gathering belongs in a dedicated service
(for example `Service/Admin/UserStatistics`), not in the controller, so it is
testable on its own.

### 4.3 Native-iOS constraint

Everything is JSON in, `application/problem+json` out, bearer-authenticated and
stateless. No browser-only input, no cookie, no HTML fallback. The design-time
checklist in `docs/architecture.md` §6 applies to the new endpoint.

## 5. Privacy

An admin viewing this page sees another person's complete feed and tag lists —
in effect their reading interests. This is a deliberate, requested capability,
restricted to admins by `adminGuard` in the SPA and by `ROLE_ADMIN` on
`^/api/admin/` server-side. The SPA guard is UX only; the server rule is the
enforcement. No new data is exposed to non-admins.

## 6. Testing

- Backend unit: the statistics service — counts, last-refresh selection, stale
  and dormant thresholds, and the empty-account case (no feeds, no tags, never
  logged in).
- Backend functional: the two endpoints, including 404 for an unknown id and
  403 for a non-admin. `lastLoginAt` stamping is covered by a **functional**
  login test on each path — a direct-invocation test would prove nothing about
  the real wiring.
- Migration: from empty on SQLite and MySQL, then `doctrine:schema:validate`.
- Frontend unit: the list's new columns, the detail page's rendering of both
  lists and of the empty state, and the actions' confirm flow.
- E2e: extend the admin smoke to open a user's detail page from the list.
- Gates: `npm run check` and `composer check` clean; PHPMD clean on every
  touched `src` file.

## 7. Out of scope

- Aggregate analytics dashboard or charts across all users.
- Editing #66's per-user limits (the block is read-only here).
- Read/activity history beyond what the existing data implies.
- Any refresh scheduler — ruled out by standing decision.
