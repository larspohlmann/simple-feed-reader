# Website scraping as an opt-in experimental preference

**Issue:** [#237](https://github.com/larspohlmann/simple-feed-reader/issues/237)
**Date:** 2026-08-02
**Status:** Approved

## Problem

Website scraping is the discovery fallback that offers a plain HTML page as a
subscribable source when the page advertises no feed. Today it is
unconditional: `FeedDiscovery::scrapeFallback()` runs for every URL that is
neither a feed nor a page with `<link rel="alternate">`, and the user is offered
a `scraped` candidate without ever asking for one.

The feature is inherently best-effort. Extraction quality depends on the target
page, and a scraped subscription can degrade at any time when the site changes.
It must therefore be opt-in, off by default, and labelled experimental.

There is no per-user preference mechanism to hang this on. `locale` and
`maxSubscriptions` are plain columns on `User`, and there is no preferences
entity, table or endpoint.

## Decisions

Four decisions were taken during design. They are recorded here because each
one closed a real alternative.

1. **The new entity holds new preferences only.** `locale` stays on `User`. It
   is read by `AccountMailer`, `AdminUserJson` and `RegistrationService`, and it
   is rendered by the admin UI. Moving it would put an auth and email path in
   the blast radius of a preferences feature for no gain here.
2. **Typed columns in a separate table**, not key/value rows and not a JSON
   blob. Key/value is stringly typed and would push validation and defaults out
   to every read site, against PHPStan level max and the repository's clean-code
   standard.
3. **Read nested in `/api/me`, write on its own endpoint.** One request still
   hydrates the whole client, which keeps a native client cheap, and the
   existing `PATCH /api/me` locale contract stays untouched.
4. **When scraping is off, the UI says nothing about it.** No hint, no upsell,
   no one-off "try scraping" action. The add-feed overlay shows its plain "no
   feed found" state. The preference is discoverable in settings and nowhere
   else.

## Data model

New entity `Preferences` in `backend/src/Entity/Preferences.php`, table
`user_preferences`, one row per user.

```php
#[ORM\Entity(repositoryClass: PreferencesRepository::class)]
#[ORM\Table(name: 'user_preferences')]
#[ORM\UniqueConstraint(name: 'uniq_preferences_user', columns: ['user_id'])]
class Preferences
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'preferences')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(options: ['default' => false])]
    private bool $scrapeFallbackEnabled = false;
}
```

`User` owns the row by composition:

```php
#[ORM\OneToOne(mappedBy: 'user', cascade: ['persist'], orphanRemoval: true)]
private ?Preferences $preferences = null;
```

The `User` constructor creates the row. This is what makes the invariant hold at
all four creation sites — `RegistrationService`, `OAuthAccountLinker`,
`BootstrapAdminProvisioner` and `E2eSeedAdminCommand` — without any of them
knowing about preferences. A future fifth creation site inherits it too.

The property is nullable only because Doctrine hydration bypasses the
constructor. `getPreferences()` returns non-null and throws `LogicException`
when the row is missing, in the same shape and for the same reason as the
existing `User::getUserIdentifier()` guard: a hydrated row without preferences
is corrupt, not a state the application supports.

### Migration

Additive, platform-aware, idempotent, `isTransactional(): false`, following
`backend/migrations/Version20260724120000.php`. It must do two things:

1. Create `user_preferences` with the MySQL and SQLite DDL variants.
2. **Backfill one row per existing user.** Without this, every account that
   predates the migration hydrates with a null `preferences` and hits the
   `LogicException` on first read.

`down()` drops the table.

Migrations are never executed by the test suite — `tests/bootstrap.php` builds
the schema from ORM metadata. This migration must be verified on its own,
from empty, on both SQLite and MySQL, followed by `doctrine:schema:validate`.

## API

`GET /api/me` gains a nested object. `MeJson::profile()` is a hand-built
allowlist by design, so the field is added there explicitly:

```json
{
  "id": 1, "email": "…", "roles": [], "status": "…",
  "createdAt": "…", "locale": "en", "trialEndsAt": null,
  "preferences": { "scrapeFallbackEnabled": false }
}
```

New write endpoint on `MeController`:

```
PATCH /api/me/preferences   →   UpdatePreferencesRequest   →   MeJson::profile()
```

`UpdatePreferencesRequest` lives in `backend/src/Dto/Me/` beside
`UpdateLocaleRequest`, and is a `final readonly class` with a validated
`bool $scrapeFallbackEnabled`.

`PATCH /api/me` and `UpdateLocaleRequest` are not touched. Overloading them was
rejected: `UpdateLocaleRequest` requires a non-blank locale, so every preference
write would have to resend the locale, or the DTO would have to go all-optional
and lose the deliberate 422-on-bad-locale guarantee added in #180.

The controller stays thin — read payload, delegate, return — per
`ThinControllerRule`.

## The gate

Two independent paths reach the scraper. Both must be closed. Gating only the
first leaves the feature reachable by a hand-made request.

### Path 1 — discovery

`FeedDiscoveryInterface::discover()` takes a second parameter. An enum, not a
boolean: boolean flag parameters are forbidden by the house style, and the enum
reads at the call site.

```php
// backend/src/Enum/ScrapeFallback.php
enum ScrapeFallback { case Enabled; case Disabled; }

public function discover(string $url, ScrapeFallback $fallback): FeedDiscoveryResult;
```

In `FeedDiscovery::discover()`, when the value is `Disabled`:

- the no-candidates branch returns `FeedDiscoveryResult::candidates([])`
  instead of calling `scrapeFallback()`;
- the empty-body branch returns `FeedDiscoveryResult::candidates([])` instead
  of `scrapeFailed('not_scrapable')`.

`blocked` and `unreachable` are genuine fetch failures, not scraping outcomes.
They still surface unchanged in both modes. **Verify during implementation that
the `failBlocked` and `failUnreachable` translations mention nothing about
scraping**; `failNotScrapable` does, and it must now be unreachable when the
preference is off.

Empty candidates with a null `scrapeFailureReason` is precisely the payload the
add-feed overlay already renders as its plain "no feed found" state. That is
what delivers decision 4 with no new frontend state.

### Path 2 — the direct scraped subscribe

`SubscriptionService::subscribe()` short-circuits on `SourceFormat::SCRAPED` and
creates the subscription **without running discovery**. `FeedPreviewService::preview()`
runs the extractor for a scraped preview on the same terms.

Both must refuse a scraped format when the preference is off, with a typed
exception namespaced next to its service (`Service/Subscription/Exception/`),
surfaced as `application/problem+json`.

### Where the preference is resolved

One service turns a user into a mode, and it is the only place that mapping
exists:

```php
// backend/src/Service/Discovery/ScrapeFallbackPolicy.php
public function forUser(User $user): ScrapeFallback;
```

`FeedDiscovery` never learns about `User`; it only ever receives the enum. The
two callers resolve the policy themselves, so the decision stays in the domain
and the controllers stay pure delegation:

- `SubscriptionService::subscribe()` already receives `User`. No signature
  change.
- `FeedPreviewService::preview()` **does not** receive `User` today — only
  `FeedPreviewController` has it. Its signature becomes
  `preview(User $user, string $url, ?string $format = null)`. Passing the
  resolved enum in from the controller was rejected: choosing whether a user
  may scrape is a security decision, and those belong in a service, not in a
  controller action.

## Frontend

### Preferences page

`frontend/src/app/settings/preferences-section.component.*` gains a toggle row
inside the existing `<app-settings-card>`, with an **experimental** badge and
one line of helper text explaining that extraction quality depends on the site
and can break without notice.

No reusable toggle component exists in `frontend/src/app/shared/` today. One is
added there, per the shared component catalog in `docs/design-language.md`.
Styles go in the sibling `.scss` file — never inline, because Stylelint has no
TS syntax installed and would not see them. No hex colours, no raw `px`.

State follows the existing `LanguageService` pattern: a signal for the value, a
`saveFailed` signal, apply-locally-then-write-through, and the HTTP write
isolated behind an injection token (`PREFERENCES_WRITER`, mirroring
`LOCALE_WRITER` in `frontend/src/app/core/locale-writer.ts`) so the component is
testable without HttpClient.

### Add-feed overlay

`frontend/src/app/reader/add-feed/add-feed-dialog.component.*`: the scraped
candidate card gains an **experimental** marker beside the existing
`dialog.addFeed.scrapedHint`.

Nothing else changes. In the off state the backend returns no scraped
candidate, so the existing "no feed found" branch renders and the overlay never
mentions scraping.

### Models and i18n

`preferences` is added to the account model in `frontend/src/app/core/`, and new
translation keys for the toggle label, helper text, experimental badge and the
overlay marker are added to **both** locales.

## Testing

Backend:

- `FeedDiscovery` unit tests for both `ScrapeFallback` values, including the
  empty-body branch.
- A test proving `subscribe()` with `format: 'scraped'` is refused when the
  preference is off — this is the bypass, and it needs its own test.
- The same for `FeedPreviewService::preview()`.
- Functional test for `PATCH /api/me/preferences`, and for `preferences`
  appearing in `GET /api/me`.
- A test that a newly constructed `User` has preferences with the default
  `false`.
- Migration verified from empty on SQLite and MySQL, then
  `doctrine:schema:validate`.

Frontend: Jest for the preferences service write-through and its failure
signal, the toggle rendering with its experimental badge, and the overlay
marker on a scraped candidate.

## Out of scope

- Moving `locale` into `Preferences`.
- Any second preference.
- Changing extraction quality, `HtmlItemExtractor`, or the scraper layers.
- Retro-active handling of subscriptions that were already created as `scraped`
  before this change. They keep working; the preference gates creation and
  preview, not refresh of an existing subscription.

## Definition of done

`composer check`, `composer md` (touched `src` files PHPMD-clean, not merely
free of new findings), `php bin/phpunit` on SQLite and on MySQL, and
`npm run check`. `backend/var/log/dev.log` scanned for deprecations after
backend work.
