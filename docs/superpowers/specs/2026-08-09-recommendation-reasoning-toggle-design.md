# Per-config "ask the model not to reason" toggle (#323, remaining scope)

## Context

`OpenAiCompatibleChatClient::request()` sends a fixed payload with no way to
tell the provider "do not think". For a reasoning model the provider default is
reasoning on, and ranking ~38 articles against a guidance prompt never needs a
thinking phase. That default was #320: a reasoning model spent minutes and
megabytes producing a small answer, and three failures on one batch killed the
run.

The LM Studio half of #323 already shipped (PR #339): the client now recovers
the answer from `reasoning_content` when `content` is empty. This spec covers
the **original** scope only — asking the provider not to reason — as a
**per-config setting**, decided with the issue author over the simpler
unconditional form.

### Why per-config, not unconditional

OpenRouter documents `reasoning: {"effort": "none"}` and it disables reasoning
entirely. LM Studio ignores the field (already tested against `bonsai-27b`, no
400). But a direct OpenAI Chat Completions endpoint rejects unrecognised
top-level arguments with a 400, and `reasoning` is not one of its Chat
Completions parameters. The standing architectural constraint keeps a direct
OpenAI endpoint viable, so a strict endpoint must be able to opt out. The
codebase has no provider-type discriminator (a configuration is a base URL plus
a key), so the opt-out lives on the configuration itself.

### Default

The toggle defaults to **on** (suppress reasoning) for new configurations and
for existing rows after the migration. This fixes #320 for the OpenRouter and
LM Studio providers this instance runs, with no action needed, and matches the
issue's premise that ranking never wants reasoning. A direct OpenAI
configuration would fail on its first run until its owner turns the toggle off;
the run now reports the real per-call error (#329), so the cause is visible.

## Behaviour

- Each `AiProviderSettings` row carries a boolean `suppressReasoning`, default
  `true`.
- When `true`, the recommendation completion request (both the batch phase and
  the dedup phase) adds `reasoning: {"effort": "none"}` to the JSON body.
- When `false`, the field is omitted and the provider's own default applies.
- The flag is editable per configuration on the settings page. It is
  independent of a connection replacement: pointing an existing configuration
  at a new endpoint or key does not reset the preference (unlike `model`, which
  a new gateway invalidates).
- The add form does not carry the toggle. A new configuration is created
  suppressing; the owner edits the flag on the row afterward.

## Backend

### Entity — `AiProviderSettings`

- New column: `suppress_reasoning TINYINT(1) NOT NULL DEFAULT 1`
  (`#[ORM\Column(options: ['default' => 1])] private bool $suppressReasoning`).
- Constructor initialises it to `true`.
- Getter `suppressesReasoning(): bool`.
- Writer `setSuppressReasoning(bool $suppressReasoning): void`. Not part of
  `replaceConnection()` — the preference survives an endpoint or key change.

### Migration

- One migration adds the column with default `1`, so every existing row becomes
  "suppress". `up()` and `down()` written for both MySQL and SQLite (SQLite via
  Doctrine's batch-rebuild, as the existing migrations do).
- Verified on the dedicated CI migrate-from-empty leg (SQLite and MySQL) plus
  `doctrine:schema:validate`.
- Applied to the running Docker MySQL after merge
  (`doctrine:migrations:migrate`), per the live-DB migration gotcha — the test
  schema is built from ORM metadata and never runs the migration.

### Request path

- `CompletionRequest` gains `bool $suppressReasoning`, carried alongside the
  model, messages, token reserve and schema.
- `RecommendationRunAdvancer::requestFor()` reads
  `$settings->suppressesReasoning()` and passes it into every
  `CompletionRequest` it builds — one place, both phases.
- `OpenAiCompatibleChatClient`: extract a private
  `completionPayload(CompletionRequest): array` that builds the JSON body and
  conditionally adds `'reasoning' => ['effort' => 'none']`. Keeps `request()`
  thin and PHPMD-clean.

### API

- New DTO `App\Dto\Ai\SetReasoningRequest { bool $suppressReasoning }`.
- `AiProviderConfigurator::setSuppressReasoning(AiProviderSettings, bool): void`
  persists the change.
- `PUT /api/me/ai/configs/{id}/reasoning`, mirroring the `rename` route:
  ownership-scoped through `AiConfigurationForUser`, no rate-limit guard (no
  provider round-trip), answers the updated configuration.
- `AiSettingsJson::configuration()` adds `suppressReasoning` to every
  configuration payload (list, added, saveModel, rename, activate, and the new
  route).

## Frontend

- `AiConfig` gains `readonly suppressReasoning: boolean`.
- `AiSettingsService.setReasoning(id: number, suppressReasoning: boolean)` —
  `PUT .../reasoning`, upserts the returned configuration.
- A checkbox per configuration row, "Ask the model not to reason", bound to
  `config.suppressReasoning`, disabled while `ai.busy()`, with a short hint that
  a strict endpoint (for example a direct OpenAI URL) needs it off. New i18n
  strings under `settings.ai.configs`.
- Styles in the sibling `.scss`, no inline styles, no hex, no raw px.

## Testing

- **Entity**: default is `true`; the writer flips it; `replaceConnection()`
  leaves it untouched.
- **Client** (`OpenAiCompatibleChatClient`): the payload contains
  `reasoning.effort = "none"` when the request suppresses, and the key is absent
  when it does not.
- **Advancer**: `requestFor()` carries the setting's flag into the
  `CompletionRequest` for both phases.
- **API**: a functional test of `PUT .../reasoning` — updates the row, returns
  the flag in JSON, 404 for another account's id.
- **Migration**: covered by the migrate-from-empty CI leg on both dialects.
- **Frontend**: service issues the PUT and upserts; the component renders the
  checkbox in the right state and calls `setReasoning` on change.
- **Mutation**: `composer infection:diff` over the changed files stays at or
  above the gate.

## Out of scope

- The `reasoning_content` fallback, the caps and the incremental reader — all
  already shipped (#329, #320, PR #339).
- Any provider-type detection or per-URL heuristic. The setting is explicit.
