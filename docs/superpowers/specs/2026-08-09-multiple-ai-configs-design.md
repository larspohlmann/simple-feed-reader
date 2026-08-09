# Save multiple AI provider configurations and switch the active one (#334)

## Problem

An account can store only one AI provider configuration. `AiProviderSettings`
is a `OneToOne` on `User` with a unique constraint on `user_id`, and it holds
one endpoint, one sealed API key (plus a four-character hint), and one chosen
model. To try a different provider or model, the user overwrites the current
connection through `replaceConnection()`, which also clears the model. Switching
back means typing the key again and re-selecting the model.

Anyone who runs "For You" against more than one provider — for example a local
LM Studio endpoint and a cloud provider — pays this cost on every switch.

## Goal

Let an account save several **named** provider configurations and switch which
one is **active** with a click. No re-typing keys. No re-selecting models when
returning to a configuration set up before.

An account that keeps a single configuration sees no behaviour change.

## Constraints carried from the codebase

- **The active configuration is a resolved `AiProviderSettings`.** Every current
  consumer reaches the provider through `AiProviderConfigurator::settingsFor()`
  or `requireConfiguration()`. If those keep returning the active row, then
  `RecommendationRunAdvancer`, `MeJson`, `AiSettingsJson::isReady()`, and the
  frontend `AiAvailabilityService` need no change to their read path.
- **A stored configuration is one that worked.** `AiProviderConfigurator` is the
  only writer, and every write is preceded by a live provider call. Switching
  keeps this rule: it re-verifies before it activates.
- **The sealed key is bound to the account id**, not to the row. Many rows per
  account seal and open with the same `identify($user)`. No crypto change.
- **Native iOS client stays viable.** Bearer auth, stateless JSON in and
  `application/problem+json` out, no browser-only inputs. Config ids are
  ownership-scoped: a config that belongs to another account reads as 404.

## Design

### 1. Data model

`AiProviderSettings` becomes the per-configuration row.

- Drop the `uniq_ai_settings_user` unique constraint.
- `user` changes from `OneToOne(inversedBy)` to **`ManyToOne`** — an account has
  many configurations. `User` holds the inverse as a collection.
- Add a nullable `name` column (VARCHAR 120). Null means "no name given"; the
  client derives a label from the endpoint host and model.
- Everything else is unchanged: `baseUrl`, the sealed key columns, `apiKeyHint`,
  `keyVersion`, `model`, `modelContextWindow`, `verifiedAt`.

`User` gains the **active pointer**:

- `activeAiProviderSettings`: a nullable `ManyToOne` to `AiProviderSettings`,
  join column `active_ai_config_id`, `ON DELETE SET NULL`. This is the single
  source of truth for "which one is active" — it cannot say two rows are active
  at once, which an `is_active` boolean per row could.

There are now two references between the two tables:

- `user_ai_settings.user_id -> user.id`, `ON DELETE CASCADE` (unchanged): delete
  the account, its configurations go with it.
- `user.active_ai_config_id -> user_ai_settings.id`, `ON DELETE SET NULL`:
  delete the active configuration, the pointer clears.

The application never relies on `SET NULL` to keep state correct — the
configurator clears the pointer explicitly before it removes a row (see §3). The
`SET NULL` rule is a database-level floor, not the mechanism.

**Optional cap:** at most 20 configurations per account. The add endpoint
refuses beyond that with a typed exception mapped to a 4xx. The number bounds
the list and the outbound verification surface; it is not a product limit anyone
is expected to reach.

### 2. Activation rule

Switching is explicit. One exception keeps the single-provider experience
intact: **when a configuration first gains a model and no configuration is
active, it auto-activates.** So an account that adds one provider and picks a
model ends up with that configuration active, exactly as today. With a second
configuration present, the account switches on purpose.

A configuration can be activated only when it **has a model** (`hasModel()`).
Activating a model-less configuration is refused — `ready` must never point at a
configuration the provider never accepted a model for.

### 3. Service: `AiProviderConfigurator`

The configurator stays the only writer. Its read methods change their resolution
but not their contract:

- `settingsFor(User): ?AiProviderSettings` returns the **active** configuration
  (`$user->getActiveAiProviderSettings()`), or null when none is active.
- `requireConfiguration(User): AiProviderSettings` returns the active one or
  throws `AiNotConfiguredException`.

New and changed writers, each named for one thing:

- `addConfiguration(User, name, baseUrl, apiKey): AddedConfiguration` — verifies
  against the provider (lists models), seals the key, persists a new row, and
  returns the row plus the offered model ids. Enforces the cap first. Does not
  set the model and does not activate (activation follows the model choice, per
  §2, through `chooseModel`).
- `chooseModel(AiProviderSettings, model): void` — unchanged in body, but after
  it stamps the model it applies the auto-activate rule: if the owning account
  has no active configuration, point the account at this row.
- `rename(AiProviderSettings, ?name): void` — sets the name. No provider call.
- `activate(AiProviderSettings): void` — re-verifies the endpoint, key, and the
  stored model against the provider (reuses the `chooseModel` verification path
  against the already-stored model id), then sets the account's active pointer.
  A failed verification throws before the pointer moves, so the current active
  configuration survives.
- `deleteConfiguration(AiProviderSettings): void` — if this row is the account's
  active one, clear the pointer first, then remove the row.
- `listConfigurations(User): list<AiProviderSettings>` — all rows for the
  account, ordered stably (by id).

Ownership is enforced where a route resolves an id to a row: a resolver returns
the row only when its `getUser()` is the current account, else the controller
answers 404. This lives in a small mapper or the repository, not in a controller
private method (thin-controller rule).

`AddedConfiguration` is a tiny read model (the row + `list<string>` model ids) so
the controller does not re-list models to build its response.

### 4. HTTP API (`/api/me/ai`)

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/me/ai` | list every configuration + which id is active |
| `POST` | `/api/me/ai/configs` | add: name + endpoint + key, verify, return the new config + models |
| `GET` | `/api/me/ai/configs/{id}/models` | model list for change-model |
| `PUT` | `/api/me/ai/configs/{id}/model` | choose or change this config's model (re-verify) |
| `PUT` | `/api/me/ai/configs/{id}/name` | rename |
| `PUT` | `/api/me/ai/configs/{id}/active` | activate: re-verify, then point the account at this config |
| `DELETE` | `/api/me/ai/configs/{id}` | delete; if active, clear the pointer first |

Notes:

- The old single-row routes are replaced. `GET /api/me/ai` now answers a list
  and an `activeId`, not one connection. `PUT /api/me/ai/connection` becomes
  `POST /api/me/ai/configs`. `PUT /api/me/ai/model` becomes the id-scoped model
  route. `DELETE /api/me/ai` (forget) becomes the id-scoped delete. There is no
  external API consumer to keep compatible — the only client is this repo's SPA,
  updated in the same change.
- The rate limiter (`aiProviderLimiter`, 30 / 15 min per account) guards every
  route that calls the provider: add, models, model, active. Rename, delete, and
  the list read do not call the provider and are not limited by it.
- Every id-bearing route resolves the id through the ownership resolver and
  answers 404 when the row is not the account's. This is the same wall an iOS
  client meets.

### 5. Response shapes (`AiSettingsJson`)

`AiSettingsJson` gains a per-configuration shape and a list shape, and keeps the
single definition of "ready" and "active":

- `configuration(AiProviderSettings, bool active): array` — id, name, derived
  label fallback data (baseUrl host is derived client-side from `baseUrl`),
  `baseUrl`, `apiKeyHint`, `model`, `ready` (has model), `active`.
- `list(list<AiProviderSettings>, ?int activeId): array` — `{ configs: [...],
  activeId }`.
- `added(AiProviderSettings, list<string> models): array` — the new config plus
  its model ids, for the add response.
- `isReady(?AiProviderSettings)` is unchanged and still the one definition
  `MeJson` reaches for the active configuration.

`MeJson` keeps reading `$user->getActiveAiProviderSettings()` in place of
`getAiProviderSettings()`; its `ai.ready` / `ai.model` output is unchanged for
the active configuration.

### 6. Frontend (`ai-section`)

The single form becomes a list plus an add form. State moves into
`AiSettingsService`.

- **List**: one row per configuration — name (or derived host + model label),
  endpoint host, model, key hint `…ab12`, an "active" badge on the active one,
  and the verified time. Row actions: **Activate**, **Change model**,
  **Rename**, **Delete**.
- **Add configuration**: name (optional) + endpoint + key. On save it verifies,
  then the row opens its model picker (the existing searchable select) to choose
  a model. Picking the first model on the first configuration activates it.
- `AiSettingsService` holds `configs`, `activeId`, `busy`, `failure`, and the
  in-progress model list for whichever row is choosing a model. Every write
  answers with the new list, so nothing re-reads the profile.
- `AiAvailabilityService` keeps being fed from the active configuration's state,
  so `MeJson`-driven availability elsewhere is unchanged.
- House style: standalone signals component, sibling `.scss` (no inline styles),
  no hex or raw px outside `theme/`, design tokens and shared components from the
  catalog. A destructive **Delete** uses the shared confirm affordance.

### 7. Migration

One Doctrine migration, verified on SQLite and MySQL (the dedicated CI leg,
`doctrine:schema:validate` from empty), and applied to the live Docker DB after
merge.

- Drop `uniq_ai_settings_user`.
- Add `user_ai_settings.name` (nullable VARCHAR 120).
- Add `user.active_ai_config_id` (nullable, FK to `user_ai_settings.id`,
  `ON DELETE SET NULL`).
- Backfill: for each existing `user_ai_settings` row that has a model, set its
  owning account's `active_ai_config_id` to that row. Rows without a model leave
  the pointer null — the account had no ready provider, so nothing becomes
  active.
- The `down` path restores the unique constraint and drops the two columns. It
  is written but, per house style, the migration is verified forward on both
  engines; the concern is the forward path on the live database.

## What this is not

- Not per-purpose routing. There is one active configuration; every AI feature
  uses it. No configuration is bound to a specific use.
- Not editable endpoints or keys in place. Endpoint and key are fixed at add
  time; to change them the account deletes the configuration and adds a new one.
  Only name and model are editable.
- Not a live health dashboard. `ready` still reports what the last successful
  verification proved, not a per-read poll.

## Testing

- **Entity / service unit tests**: a user holds many configurations;
  `settingsFor` returns the active one and null when none is active; the
  auto-activate rule fires only when no configuration is active; `activate`
  moves the pointer only after a successful verification and leaves it on
  failure; `deleteConfiguration` clears the pointer when it removes the active
  row; the cap refuses the twenty-first add.
- **Functional tests per endpoint**: add → choose model → auto-activate; add a
  second, activate it, confirm `MeJson` and `GET /api/me/ai` follow; rename;
  delete a non-active row; delete the active row and confirm the account reports
  no active configuration; **cross-user 404** on every id-bearing route; the
  rate limiter fires on the provider-calling routes.
- **Migration**: covered by the CI migrate-from-empty leg on both engines plus
  `doctrine:schema:validate`; the backfill is asserted by a test that seeds a
  pre-migration-shaped row and checks the pointer lands. Because
  `tests/bootstrap.php` builds the schema from metadata, the backfill SQL itself
  is exercised in the CI migration leg, not the ordinary suite.
- **Mutation**: `composer infection:diff` gates the changed files; the
  active-resolution and auto-activate branches must be killed.
- **Frontend Jest**: list renders configs with the active badge; add flow posts
  and opens the model picker; activate calls the active route and updates the
  badge; delete confirms then removes; the derived-label fallback shows when
  name is null.

## Out-of-band steps after merge

- Apply the migration to the live Docker MySQL:
  `docker compose exec php bin/console doctrine:migrations:migrate`.
- Restart the worker so it loads the new code
  (`docker compose restart worker`) — the worker holds code from startup.
