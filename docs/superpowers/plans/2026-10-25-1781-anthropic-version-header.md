# #1781 Anthropic model list needs `anthropic-version` — Implementation Plan

**Goal:** Connecting `https://api.anthropic.com/v1` as an AI provider succeeds, and its models carry a context window.

**Architecture:** One file, `backend/src/Service/Ai/ModelCatalog/OpenAiCompatibleCatalog.php`. The model-list request
sends `anthropic-version: 2023-06-01` unconditionally (no host check; other OpenAI-compatible providers ignore it), and
`reportedContextWindow()` also reads `max_input_tokens`. Chat completions are untouched: Anthropic's compatible
`/chat/completions` answers without the header.

## Task 1: version header (TDD)

- [ ] In `OpenAiCompatibleCatalogTest`, add `testItSendsTheAnthropicVersionHeader`, modelled on
      `testItRefusesTransparentCompression`, asserting `anthropic-version: 2023-06-01` is among the sent headers.
- [ ] Run it; expect FAIL.
- [ ] Add `'anthropic-version' => self::ANTHROPIC_VERSION` to `request()`'s headers, with the constant on the class.
- [ ] Run it; expect PASS.

## Task 2: `max_input_tokens` as a window (TDD)

- [ ] Add a `listedEntries()` case `'a model reporting max_input_tokens (Anthropic)'`:
      `['id' => 'claude-haiku-5-5', 'max_input_tokens' => 1_000_000]` → `new ModelDescriptor('claude-haiku-5-5', 1_000_000)`.
- [ ] Run; expect FAIL. Add `'max_input_tokens'` to the field list in `reportedContextWindow()`. Run; expect PASS.

## Task 3: gates and live check

- [ ] `php bin/phpunit tests/Service/Ai`, `composer check`, `composer md`, `composer infection:diff`.
- [ ] Live: `curl` the Anthropic `/v1/models` with the app's exact headers → 200.
- [ ] Commit `fix(#1781): …`, push, PR into `develop` with `Closes #1781`.
