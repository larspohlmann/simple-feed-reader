# Per-account AI provider (groundwork) (#305)

**Goal:** an account can name an OpenAI-compatible endpoint, save an API key for
it, discover the models that endpoint offers, and choose one. The stored key
survives a database dump without being readable. When the three values are set
and proven to work, the SPA holds a single `aiReady` signal that later AI
features read.

This ticket ships **no AI feature**. It ships the configuration, the secret
handling, and the availability flag those features will stand on.

## Behaviour

### Configuring a provider

The account opens **Settings → AI**, enters a base URL and an API key, and
saves. The save calls the provider's `GET {baseUrl}/models` before it writes
anything:

- the provider answers with a model list → the key is sealed and stored, and
  the list fills a searchable dropdown;
- the provider refuses the key, or does not answer → `422` and the previously
  stored configuration is untouched.

The account then picks a model from the dropdown and saves again. That save
re-reads the list and refuses a model the provider does not offer.

A configured account sees its base URL, the masked key (`••••••••3f7a`), and
the chosen model. It can re-read the model list with the stored key, so it can
change the model without retyping the key. A delete action removes the whole
row.

### What "AI is available" means

`ready` is true when a base URL, a key and a model are stored, and the last
save proved all three against the provider. It is not a cached health check: it
records that the configuration was correct when it was written, which is the
strongest claim a save can make without polling the provider.

## Securing the key

### Secret material

A new environment variable `AI_KEY_SECRET` holds 64 hex characters — the shape
`generate_secret` already produces everywhere else in this project. The cipher
uses the string as HKDF input keying material, so it is never decoded.

It follows the `ALTCHA_HMAC_KEY` pattern:

- `scripts/install.sh` generates it with `generate_secret`;
- `scripts/lib.sh` lists it in `ENV_PROD_REQUIRED`;
- it lives in `.env.local` on the server. It is not in git and not in the
  database.

One addition to that pattern: `scripts/prod-start.sh` calls a new
`ensure_ai_key_secret`, which generates the value when it is still empty. This
mirrors `ensure_admin_setup_secret` and exists because the running instance has
no such variable. Without it, the first deploy of this branch would boot a
container whose `%env(AI_KEY_SECRET)%` cannot resolve, and every route would
fail — a site-wide outage for a feature nobody has switched on yet.

For the same proportionality reason, `InsecureProductionConfigGuard` is **not**
extended. That guard refuses to serve the whole instance, which is right for a
void CAPTCHA or a black-hole mailer — both are silent, global failures. An
optional per-account feature does not warrant taking the site down, and the
deploy path already guarantees a real secret.

### Derivation and encryption

Every stored key carries its own random 16-byte salt. The row key is

```
hash_hkdf('sha256', $masterSecret, 32, "ai-api-key|v{$keyVersion}|user:{$userId}", $salt)
```

so two accounts never share a key, and two saves by one account never share a
key.

The key itself is sealed with `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt`
under a fresh 24-byte nonce. The additional authenticated data is
`v{$keyVersion}|user:{$userId}`, built by the same private method that builds
the HKDF info, so the two can never drift apart.

Both bindings do work:

- **owner:** an attacker with write access who moves a ciphertext into another
  account's row gets a failed tag check, not another account's key;
- **version:** the same attacker cannot lower a row's `key_version` to reach an
  older scheme once a second version exists. The version is read from the row
  before derivation, and a value the cipher does not know is refused outright
  rather than treated as version 1.

The derived row key is cleared with `sodium_memzero` on every exit from `seal()`
and `open()`. The plaintext API key is not: it is passed in and returned as an
ordinary PHP string, which the engine may already have copied, so wiping the one
variable this class holds would clear a copy rather than the secret. The row key
is the material worth wiping — it is derived here, used here, and nothing outside
this class ever sees it.

### What this protects against

- **Database dump alone:** useless. The master secret is not in the database.
- **Dump plus one account's row read by another account:** blocked by the AAD
  binding and by the per-row salt.
- **Dump plus `.env.local`:** the keys are readable. This is unavoidable — the
  server has to use the key when the account holder is not present. Deriving
  from the account password would remove that property and break every stored
  key on a password change.

Encryption is at rest only. During a request the key is plaintext in memory and
travels to the provider over the provider's own transport.

`api_key_hint` holds the last four characters in clear text on purpose, so the
settings page can identify which key is stored.

The `key_version` column carries the scheme version, currently `1`. It is not
bookkeeping: it feeds both the HKDF info and the AAD, as above. A later scheme
change adds a version, keeps the old one readable, and re-seals each row on its
next successful save. No re-key command is written now.

## Endpoint policy — a recorded exception

The base URL does **not** pass through `UrlGuard`. Private, loopback and
link-local targets are accepted, so an account can point at a local provider
(Ollama, LM Studio).

This is a deliberate exception to the standing SSRF boundary in `CLAUDE.md`,
decided by the repository owner on 2026-08-06. The accepted risk: any account
that reaches the settings page can make the server issue requests to hosts on
the server's own network and observe whether they answer. The mitigation is
that registration is approval-gated, so every account is one the operator
admitted.

The guards that remain are not SSRF guards and cost nothing:

- `http` and `https` schemes only, credentials in the URL rejected;
- a 10-second timeout;
- a 1 MB response cap;
- `RateLimitGuard` on both verifying endpoints, so the pair cannot be driven in
  a loop.

If the instance ever opens registration, this decision has to be revisited.

## Persistence

New entity `AiProviderSettings`, table `user_ai_settings`, one-to-one to `User`
with `onDelete: 'CASCADE'`.

Unlike `Preferences`, the row is **not** created by the `User` constructor: most
accounts never configure a provider, and "no row" is the natural expression of
"not configured". `AccountDeleter` needs no change — the FK cascade removes the
row with the account, the same way it removes preferences today.

| Column | Purpose |
|---|---|
| `base_url` | as entered, trailing slash trimmed |
| `api_key_ciphertext` | base64 |
| `api_key_nonce` | base64 |
| `api_key_salt` | base64 |
| `api_key_hint` | last four characters, clear text |
| `key_version` | scheme version, `1` |
| `model` | nullable until the account picks one |
| `verified_at` | when the last successful verification ran |

The three binary values are stored base64-encoded in `VARCHAR`, so one
migration works on both MySQL and SQLite.

## Backend structure

`src/Service/Ai/`:

| Class | Responsibility |
|---|---|
| `Crypto/SealedApiKey` | value object: ciphertext, nonce, salt, version |
| `Crypto/ApiKeyCipher` | `seal(int $userId, string $plainKey)`, `open(int $userId, SealedApiKey $sealed)` |
| `Crypto/Exception/ApiKeyUnreadableException` | failed tag check: wrong master secret or a tampered row |
| `ProviderCredentials` | value object: base URL and key |
| `ModelCatalog` (interface) | `listModels(ProviderCredentials $credentials): ModelList` |
| `OpenAiCompatibleCatalog` | the `GET /models` implementation over `HttpClientInterface` |
| `AiProviderConfigurator` | verify, then persist. The only writer |
| `Exception/ProviderUnreachableException` | the endpoint did not answer |
| `Exception/CredentialsRejectedException` | the endpoint answered `401`/`403` |
| `Exception/ModelNotOfferedException` | the chosen model is not in the list |

Failures are typed exceptions, never a `null` return.

## HTTP contract

`AiSettingsController`. Every action reads the request, delegates, and returns —
no private methods, per `ThinControllerRule`.

| Route | Method | Body | Returns |
|---|---|---|---|
| `/api/me/ai` | `GET` | — | state |
| `/api/me/ai/connection` | `PUT` | `baseUrl`, `apiKey` | state and `models[]` |
| `/api/me/ai/models` | `GET` | — | `models[]` |
| `/api/me/ai/model` | `PUT` | `model` | state |
| `/api/me/ai` | `DELETE` | — | `204` |

`GET /api/me/ai/models` re-reads the list with the **stored** credentials. It is
what an already-configured account uses to change its model later, without
retyping the key.

State is `{ configured, baseUrl, apiKeyHint, model, ready }`. The API key is
never returned by any endpoint.

Connection and model are two endpoints, not one. A single endpoint would need a
nullable `apiKey` meaning "keep the stored one" — a hidden flag in the contract
and a poor shape for a native client. Each endpoint does one thing.

Errors are `application/problem+json`, as everywhere else. The unreachable and
rejected cases are `422`, so the client can show which of the two happened.

`MeJson::profile()` gains:

```json
"ai": { "ready": true, "model": "gpt-4o-mini" }
```

`ready` is decided in exactly one place — `App\Http\AiSettingsJson::state()`, the
mapper that puts it on the wire. `MeJson` delegates to it rather than repeating
the rule, so no second implementation can drift.

### Native client check

Bearer token, stateless, JSON in and `application/problem+json` out, no
browser-only input, no `text/html` fallback. The masked key is a display
string the client may render as it likes. The contract passes the
`docs/architecture.md` §6 checklist.

## Frontend

### `app-searchable-select` (new shared component)

`src/app/shared/searchable-select/`. A text filter above a `role="listbox"`,
driven by arrow keys, `Enter` and `Escape`, dismissed by the existing
`dismiss-on-outside` directive. It takes plain `{ value, label }` options, so
the tag and catalog forms can adopt it later. Styles live in a sibling `.scss`
and use theme tokens only — no hex, no raw `px`.

### Settings section

One entry in `SETTINGS_SECTIONS` (`path: 'ai'`, icon `smart_toy`, group
`general`), one lazy route, and `ai-section.component.{ts,html,scss,spec.ts}`.
The section holds the base URL field, the key field, the model dropdown, and
the delete action.

### The availability signal

A core `AiAvailabilityService` holds a `ready` signal.
`AuthService.loadMe()` adopts it the way it already adopts
`PreferencesService`; `AuthService.logout()` resets it, so the next account
does not inherit the previous one's state (#263). The AI settings section
updates it after a save.

Later AI features read one signal and make no extra request.

New keys go into both `en.json` and `de.json`.

## Testing

**Backend unit**

- cipher round trip;
- `open()` with a different user id fails;
- `open()` with an altered `key_version` fails;
- an unknown `key_version` is refused, not silently treated as version 1;
- one flipped ciphertext byte fails;
- two seals of the same key produce different ciphertexts;
- catalog parsing over `MockHttpClient`: a good list, `401`, malformed JSON, a
  body over the cap.

**Backend functional**

- all five endpoints;
- `401` without a token;
- one account cannot read or overwrite another account's row;
- a failed verification leaves the stored configuration unchanged.

**Frontend Jest**

- dropdown filtering and keyboard navigation;
- the section's save-then-pick flow, including the `422` path.

**Other gates**

The migration runs in the existing dedicated CI leg on both SQLite and MySQL.
Infection gates the files this branch changes.

## Out of scope

Any AI feature that uses the configuration. Instance-wide provider defaults.
Key rotation tooling. Usage or cost accounting. Streaming.
