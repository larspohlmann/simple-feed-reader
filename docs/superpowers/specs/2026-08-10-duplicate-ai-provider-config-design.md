# Duplicate an AI provider configuration

- **Issue:** #347
- **Date:** 2026-08-10
- **Status:** Design approved; implementation plan pending.

## Problem

An account can hold several AI provider configurations (`AiProviderSettings`
rows). To run the same provider with a different model, the user must create a
new configuration and re-enter the API key. The key is stored **encrypted** and
never sent to the client — the browser only ever sees `apiKeyHint` (the last 4
characters). So the client cannot copy a key on its own. Re-entering the key is
friction every time the same provider is set up with a different model.

## Goal

Add a **Duplicate** action to each configuration. It creates a new
configuration that reuses the source's base URL and API key (re-sealed
server-side), starting with **no model**, so the user picks a different model
without re-entering the key.

## Non-goals

- Editing the base URL or key of an existing configuration (still delete +
  re-add).
- Any change to how keys are encrypted, sealed, or verified.
- A live provider re-verification on duplicate (see "Verification" below).

## Constraints from the current design

- **The key is server-only.** It is sealed with libsodium
  (`ApiKeyCipher`, `xchacha20poly1305_ietf`), bound to the `userId` and a scheme
  version. It can only be read back by `ApiKeyCipher::open(userId, sealed)`
  inside the service. A duplicate therefore **must** be a server-side operation;
  it cannot be a client-side form copy.
- **`AiProviderConfigurator` is the only writer** of `AiProviderSettings`. The
  new behaviour lives there.
- **Per-account limit** `MAX_CONFIGURATIONS = 20` applies.
- **`{id}` routes resolve through `AiConfigurationForUser::require`**, which
  returns `404` (not `403`) for a foreign or missing id. Duplicate follows the
  same rule.
- **Response shaping is hand-built** in `Http/AiSettingsJson.php` so sealed key
  material is never serialized. Duplicate reuses `AiSettingsJson::configuration`.

## Behaviour

### What the new configuration carries over

| Field | Value on the copy |
|---|---|
| `baseUrl` | copied from source |
| API key (sealed columns) | source key, **re-sealed into a fresh row** (new salt/nonce, bound to the same `userId`) |
| `apiKeyHint` | copied from source (same key → same last 4) |
| `suppressReasoning` | copied from source |
| `batchConcurrency` | copied from source |
| `verifiedAt` | copied from source (identical credentials; no live call) |
| `name` | `"Copy of <name>"`, or `"Copy"` when the source is unnamed |
| `model` | **null** — the copy is not ready until a model is chosen |
| active pointer | **not** activated |

### Verification

Duplicating does **not** run the live model-list call that `addConfiguration`
runs. The credentials are identical to a configuration that already verified, so
a duplicate is trivially valid; `verifiedAt` is carried over unchanged. A key
revoked since the source was verified is not caught at duplicate time — it
surfaces the first time the copy is used or a model is chosen, which is
acceptable and avoids a network round-trip that can fail on a provider outage.

## Backend

### Service

New method on `AiProviderConfigurator`:

```
duplicateConfiguration(User $user, int $sourceId): AiProviderSettings
```

1. Enforce `MAX_CONFIGURATIONS` (reject with the existing "too many
   configurations" exception when the account is already full).
2. Resolve the source via `AiConfigurationForUser::require($user, $sourceId)`
   (`404` on foreign/missing id).
3. Open the source's sealed key with `ApiKeyCipher::open`.
4. Build a new `AiProviderSettings` for the same user with the carried-over
   fields above; re-seal the plaintext key into the new row; leave `model` null;
   do not activate.
5. Persist and return the new row.

The opened plaintext key stays inside the method — it is only passed to
`ApiKeyCipher::seal` for the new row and never returned or logged.

### Controller

New action on `AiSettingsController`:

```
POST /api/me/ai/configs/{id}/duplicate   name: api_me_ai_duplicate   {id} = \d+
```

- Rate-limited by the existing `aiProviderLimiter`, like the other
  provider-touching routes.
- No request body, therefore no new request DTO.
- Delegates to `AiProviderConfigurator::duplicateConfiguration` and returns
  **`201`** with `AiSettingsJson::configuration(...)`:
  `{ id, name, baseUrl, apiKeyHint, model: null, suppressReasoning,
  batchConcurrency, ready: false, active: false }`.
- The action stays thin (read id, delegate, return), per `ThinControllerRule`.

## Frontend

- `AiSettingsService`: add `duplicate(id: number)` that `POST`s to
  `/api/me/ai/configs/{id}/duplicate`, then `upsert()`s the returned `AiConfig`
  into the `configs` signal and recomputes `AiAvailabilityService` — the same
  pattern the other mutations use.
- `ai-section.component`: add a **Duplicate** button to each configuration row,
  alongside Change model / Rename / Delete. After it returns, the new
  "Copy of …" row appears as not-ready; the existing **Change model** action on
  that row picks the different model.

## Error handling

- Account already at 20 configurations → the existing "too many" error path
  (same as `addConfiguration`), surfaced through the existing `AiFailure`
  mapping on the client.
- Foreign or missing id → `404` via `AiConfigurationForUser::require`.
- No new network-failure path is introduced, because there is no live provider
  call.

## Testing

- **Backend unit/integration (`AiProviderConfigurator`):**
  - the re-sealed key opens to the **same plaintext** under the new row
    (falsifiable: a wrong userId/salt binding fails to open);
  - `apiKeyHint`, `suppressReasoning`, `batchConcurrency`, `verifiedAt` copied;
  - `model` is null and the copy is not active;
  - name is `"Copy of <name>"`, and `"Copy"` when the source is unnamed;
  - `MAX_CONFIGURATIONS` rejection when full;
  - foreign-id `404`.
- **Backend functional (controller):** `POST .../duplicate` returns `201`, the
  documented shape, and **no `apiKey*` fields** in the JSON.
- **Mutation testing:** `composer infection:diff` over the changed files, per the
  CI ratchet.
- **Frontend (Jest):** `duplicate()` upserts the returned config and recomputes
  availability; the component button calls the service with the row id.

## Native iOS readiness

The endpoint is bearer-token, stateless, JSON-in/JSON-out, no browser-only
input, `application/problem+json` on error. It satisfies the standing
native-client checklist.
