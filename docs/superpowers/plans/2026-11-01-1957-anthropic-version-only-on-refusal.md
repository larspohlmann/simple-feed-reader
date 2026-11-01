# #1957 — send anthropic-version only when the listing is refused without it

**Goal:** OpenRouter lists its full catalog again; Anthropic keeps connecting.

**Cause:** #1781 sends `anthropic-version` on every `GET {base}/models`. An authenticated OpenRouter request
carrying it answers with the Anthropic-compatible listing (20 `anthropic/…` ids instead of 648), so the
dropdown shows only those and activation of any other model fails with `ModelNotOfferedException`.

**Design:** `OpenAiCompatibleCatalog::readBody()` requests without the header. A `400` answer (Anthropic's
refusal of a missing version) triggers exactly one retry with `anthropic-version: 2023-06-01`; the retry's
answer goes through the existing status handling. No host check.

## Tasks

1. **Tests first** (`tests/Service/Ai/ModelCatalog/OpenAiCompatibleCatalogTest.php`), all red before the change:
   - an answered listing makes one request and carries no `anthropic-version`
     (replaces `testItSendsTheAnthropicVersionHeader`);
   - a `400` on the plain request is retried once with the header, and the retry's models are returned;
   - a `400` on the retry too is `ProviderUnreachableException` with status 400, after exactly two requests;
   - a `401` is not retried (one request, `CredentialsRejectedException`).
2. **Implement** in `src/Service/Ai/ModelCatalog/OpenAiCompatibleCatalog.php`: `request()` takes the extra
   headers; `readBody()` retries on `Response::HTTP_BAD_REQUEST`. Update the header comment.
3. **Gates:** `composer cs`, `composer stan`, `composer md`, `composer tramp`, the catalog test, then
   `composer test:parallel` on SQLite; `composer infection:diff` over the changed file.
4. **Live check:** the configuration-6 probe (OpenRouter, stored key) lists `qwen/qwen3.7-flash` through the
   real catalog; nothing printed but counts.
5. `/simplify`, commit `fix(#1957): …`, PR into `develop` with `Closes #1957`.
