# Email digest of new saved-search entries — Spec (#636)

**Status:** agreed in grilling on 2026-08-28. Implements GitHub issue #636.

## Goal

When an instance can send mail, a user opts in to a recurring email digest of
new entries that match their saved searches. The user chooses **daily** or
**weekly**, a **send hour**, and (for weekly) a **weekday**, and enables the
digest **per saved search**. This is the first content type; more come later.

## Product decisions (from the design interview)

1. **One feature branch**, built in vertical slices: model+migration →
   endpoints+JSON → composer+mailer → scheduling → Email settings section →
   per-search toggle. One PR.
2. **Timezone:** the send hour is interpreted in a single **instance timezone**
   read from `APP_TIMEZONE` (default `UTC`). No per-user timezone picker in v1.
   All timezone logic is isolated in one collaborator so a per-user timezone can
   replace it later without touching callers.
3. **Verified email is required** to receive a digest. There is no `isVerified`
   field today; verification maps to `UserStatus`, but a mailless-registered
   `Active` user can exist with an unverified address, and mail may be enabled
   after registration. So we add a real signal: `User::$emailVerifiedAt`.
4. **Empty digest is skipped** — nothing is sent, and `digestLastSentAt` is
   **not** advanced, so a late-arriving entry from that window is still included
   next time.
5. **First enable seeds `digestLastSentAt` to the enable moment** (now, UTC), so
   the first digest covers only entries that arrive after opt-in.
6. **A "send test digest" button**, covering the last *N* days (*N* chosen by the
   user, 1–30, default 7). It sends immediately, respects the per-search flags,
   does not change `digestLastSentAt`, and works when the digest is off. It is
   rate-limited.
7. **Deep links reuse `APP_FRONTEND_URL`** (the env value `AccountMailer` already
   uses for verification links) — no new admin setting. Entry links use the
   robust bare-id form `{base}/?entry={id}`; saved-search links use
   `{base}/?q={term}`.
8. **The digest mail is plain text**, grouped by saved-search term with a count
   per term, each entry line = title + feed name + short description, capped at
   `DIGEST_ENTRIES_PER_SEARCH = 10` per search with a "+N more →" link to that
   search. The content model is separated from the renderer so an HTML renderer
   can be added later.
9. **The worker-less path folds into the existing general maintenance tick**
   (`POST /maintenance/tick` → `MaintenanceTick`), not a new endpoint. Worker
   installs get a new recurring `SendDueDigests` message. Both call one shared
   `SendDueDigests` service.
10. **A new `email` settings section**, always present in the menu. A top info
    box drives three states: mail disabled, mail-on-but-unverified (with a
    resend-verification link), and mail-on-and-verified (full controls).
11. **A mail icon per saved search in the reader sidebar** toggles the flag with
    a confirm dialog in both directions. The icon is always outlined; when the
    search is not included it is very transparent. The icon is **hidden** when
    `MAIL_DISABLED`.

## Assumptions this spec locks in (flag if wrong)

- **Digest content = unread matches in the arrival window.** For each included
  saved search, the digest lists entries whose `effectiveDate` is after
  `digestLastSentAt` **and** that are still unread, across the user's subscribed
  feeds. This reuses the existing bounded unread-match query (one LIKE scan per
  search, #584) plus a `> :since` filter, and never re-notifies an entry the
  user already read in the app.
- **Entity columns are non-null with defaults** (not nullable-when-off), because
  the digest write always sends the full digest config. `digestLastSentAt` is the
  only nullable field (genuinely absent until first enable).
- **Digest write is its own endpoint** `PATCH /api/me/digest`, not folded into
  `UpdatePreferencesRequest` — the codebase already split locale out of the
  preferences PATCH for exactly this "don't force resend the other fields"
  reason (see `MeController::updatePreferences` docblock, #180).

## Data model

### `Preferences` (existing mutable entity, table `user_preferences`)

New columns:

| Property | Column | Type | Default |
|---|---|---|---|
| `digestEnabled` | `digest_enabled` | bool | `false` |
| `digestCadence` | `digest_cadence` | string(10) | `'daily'` |
| `digestSendHour` | `digest_send_hour` | smallint | `8` |
| `digestWeekday` | `digest_weekday` | smallint | `1` (Mon, ISO-8601) |
| `digestLastSentAt` | `digest_last_sent_at` | datetime_immutable, **nullable** | `null` |

`digestCadence` is a string column backed by a `DigestCadence` string-enum
(`daily`, `weekly`).

### `SavedSearch` (existing mutable entity, table `saved_search`)

New column: `includeInDigest` / `include_in_digest`, bool, default `false`.

### `User` (existing entity, table `app_user`)

New column: `emailVerifiedAt` / `email_verified_at`, datetime_immutable,
**nullable**, default `null`. Set on:
- verify-email token consumption (`RegistrationService::verifyEmail`),
- OIDC login where the provider vouches for the address.

**Backfill migration:** for existing users whose status is `Active` or
`PendingApproval`, set `email_verified_at = COALESCE(approved_at, created_at)`.
Correct for any instance that had mail enabled at registration (this one).

## Backend surface

### Endpoints (all errors `application/problem+json`)

| Method | Path | Purpose |
|---|---|---|
| `PATCH` | `/api/me/digest` | Set `enabled, cadence, sendHour, weekday` (new `UpdateDigestRequest`) |
| `POST` | `/api/me/digest/test` | Send a preview over the last `days` (1–30); rate-limited |
| `POST` | `/api/me/resend-verification` | Reissue a `VerifyEmail` token; gated on `MailCapability` |
| `PATCH` | `/api/saved-searches/{id}` | Set `includeInDigest` (new action on `SavedSearchController`) |

### `/api/me` payload additions (`MeJson::profile`)

```
'mail' => ['enabled' => bool],                    // from MailCapability
'emailVerified' => bool,                          // $user->getEmailVerifiedAt() !== null
'preferences' => [
    'scrapeFallbackEnabled' => bool,              // existing
    'digest' => [
        'enabled' => bool,
        'cadence' => 'daily'|'weekly',
        'sendHour' => int,
        'weekday' => int,
    ],
],
```

`SavedSearchJson::one` gains `'includeInDigest' => bool`.

### Services (`Service/Mail/Digest/` unless noted)

- `DigestCadence` (enum, `Service/Mail/Digest/`).
- `DigestSchedule` — owns the timezone. `mostRecentDue(Preferences, \DateTimeImmutable $nowUtc): ?\DateTimeImmutable` returns the most recent scheduled occurrence at/before now (converted to UTC), or `null` if none has passed. Reads `APP_TIMEZONE`.
- `DigestEntryFinder` — for one included saved search, returns capped, hydrated rows + total count since a datetime. Wraps a new `EntryListRepository::unreadMatchIdsSince(query, since)` + `rowsByIdsForUser`.
- `DigestComposer` — builds a `DigestModel` for a user over a window `[since, now]` across their included saved searches. Returns `null` when empty.
- `DigestModel` / `DigestGroup` / `DigestEntry` — plain value objects (the HTML-later seam).
- `DigestTextRenderer` — `render(DigestModel): array{subject: string, body: string}`, translated, domain `emails`.
- `DigestMailer` (+ `DigestMailerInterface` + `MailGatedDigestMailer` decorator) — sends a composed digest to a user; the decorator no-ops + logs when mail is off, mirroring `MailGatedAccountMailer`.
- `SendDueDigests` — finds every user with the digest due (enabled, mail on, verified, `DigestSchedule` says due), composes, sends on non-empty, advances `digestLastSentAt` to the occurrence instant. Called by the worker message handler and by `MaintenanceTick`.
- `DigestLinkBuilder` — builds `{base}/?entry={id}` and `{base}/?q={term}` from `APP_FRONTEND_URL`.

### Scheduling

- Worker: new marker `App\Service\Worker\Message\SendDueDigests` + handler, added to `WorkerSchedule::getSchedule()` as `RecurringMessage::every('1 hour', new SendDueDigests())`.
- Worker-less: `MaintenanceTick::run()` calls the `SendDueDigests` service after the recommendation sweep, reporting its outcome in `MaintenanceTickReport`.
- Dueness fires at most daily/weekly, so an hourly tick is enough.

## Frontend surface

### New `email` settings section

- `settings-sections.ts`: `{ path: 'email', icon: 'mail', labelKey: 'settings.email.title', group: 'general' }` (placed after `preferences`).
- `settings.routes.ts`: one lazy child route → `EmailSectionComponent`.
- `email-section.component.{ts,html,scss}` modelled on `preferences-section`.
- State source: a new `DigestService` (core), parallel to `PreferencesService`, adopting from `loadMe()` and resetting on sign-out; writes via a `DIGEST_WRITER` token (`HttpDigestWriter` → `PATCH /api/me/digest`). Mail capability + `emailVerified` read from `AuthService.user()`.
- Three states via a top info box (a small local callout in the component; check `docs/design-language.md` for a token first):
  - mail disabled → box explains the instance cannot send mail; controls disabled.
  - mail on, not verified → box + resend link (`POST /api/me/resend-verification`); controls disabled.
  - mail on, verified → no box; full controls.
- Controls: master toggle, cadence (daily/weekly), send-hour select, weekday select (weekly only), the included-saved-searches list (each row an `app-toggle` → `PATCH /api/saved-searches/{id}`), and the test-mail row (days input 1–30 + send button, rate-limit feedback).

### Reader sidebar toggle

- A mail icon action on each saved-search row in `sidebar.component.html`, shown only when `mail.enabled`.
- Always outlined `app-icon name="mail"`; a `.muted` class drops opacity when not included.
- Click → confirm dialog (both directions) via `ConfirmDialogComponent`, modelled on `reader-shell`'s `confirmRemoveSavedSearch`; on confirm → `SavedSearchesStore.setIncludeInDigest(id, value)` → new `ReaderApi.updateSavedSearch(id, {includeInDigest})` → `PATCH /api/saved-searches/{id}`.
- `SavedSearchWire` + `SavedSearchDto` gain `includeInDigest`; the store maps it through and patches it optimistically.

## Global constraints (carried from CLAUDE.md)

- PHP 8.4, Symfony 7.4; `declare(strict_types=1)` in every file; PSR-12.
- Clean Code: `final readonly` value objects, guard clauses, no boolean-flag
  params (split methods), depend on interfaces, typed namespaced exceptions,
  thin controllers (no responsibility-carrying private methods).
- `composer check` (cs + stan-max + tramp), `composer md`, PhpStorm inspections
  on changed PHP must pass. Every `src` file touched must be PHPMD-clean.
- Doctrine stores **naive UTC**; normalise before persist.
- Migrations get the dedicated CI leg (SQLite + MySQL, `schema:validate`); the
  test suite builds schema from metadata, so migrations need their own check.
- Mutation testing gates changed files (`composer infection:diff`); parallel
  runs set `TEST_TOKEN`.
- Frontend: standalone components + signals, no NgModules; no hex or raw px in
  `.scss` outside `theme/`; styles in a sibling `.scss` (`styleUrl`); i18n keys
  added to **both** `en.json` and `de.json`.
- Native-iOS constraint: bearer auth, stateless, JSON in / `problem+json` out,
  no browser-only inputs.
