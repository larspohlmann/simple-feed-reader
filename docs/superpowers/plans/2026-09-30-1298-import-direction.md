# Frontend import direction — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (inline, one task). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Zero `boundaries/dependencies` findings, with the rule flipped to `error` (#1298).

**Architecture:** Pure file moves. There are no behaviour changes. Each module moves to the layer that owns it:
- The OPML export helper serves only settings, so it moves to settings.
- `search-marks` is what `shared/marked-text` renders, so it moves to shared.
- `SetupService` and `SetupApi` are used by reader, settings and auth, so they move to core.
- The catalog data (store, api, models, onboarding skip) is used by the reader shell and entry list, so it moves to `reader/catalog/`. `discover` becomes a page over reader data, and discover → reader is allowed.

**Tech Stack:** Angular 20, ESLint boundaries (from #1297).

## Global Constraints

- Starts after #1297 has merged. Branch `refactor/1298-import-direction` off `develop`. Commit format `type(#1298): summary`. First commit copies this plan to `docs/superpowers/plans/`.
- Gate: `docker compose exec -T frontend npm run check`. `npm run build` (inside the container) also catches a missed non-spec import.
- Moves use `git mv`, so history follows.

---

### Task 1: Move the modules and flip the rule

**Files:** listed per step.

- [ ] **Step 1: Move** (from `frontend/src/app`):

```bash
git mv core/opml-export.ts settings/opml-export.ts
git mv reader/search-marks.ts shared/marked-text/search-marks.ts
git mv reader/search-marks.spec.ts shared/marked-text/search-marks.spec.ts
git mv setup/setup.service.ts core/setup.service.ts
git mv setup/setup.service.spec.ts core/setup.service.spec.ts
git mv setup/setup-api.ts core/setup-api.ts
mkdir -p reader/catalog
for f in catalog.store catalog.store.spec catalog-api catalog-api.spec catalog.models onboarding-skip onboarding-skip.spec; do git mv discover/$f.ts reader/catalog/$f.ts; done
```

- [ ] **Step 2: Re-point the moved files' own relative imports**

| File | Old | New |
|---|---|---|
| `settings/opml-export.ts` | `./problem`, `./save-as`, `../reader/reader-api` | `../core/problem`, `../core/save-as`, `../reader/reader-api` |
| `core/setup-api.ts` | `../core/api` | `./api` |
| `reader/catalog/catalog.store.ts` | `../core/problem`, `../core/session-identity` | `../../core/problem`, `../../core/session-identity` |
| `reader/catalog/catalog-api.ts` | `../core/api` | `../../core/api` |
| `reader/catalog/catalog.models.ts` | `../reader/models` | `../models` |
| `reader/catalog/onboarding-skip.ts` | `../core/session-identity` | `../../core/session-identity` |
| `reader/catalog/catalog.store.spec.ts` | `../core/api`, `../core/token.store` | `../../core/api`, `../../core/token.store` |
| `reader/catalog/catalog-api.spec.ts` | `../core/api` | `../../core/api` |
| `reader/catalog/onboarding-skip.spec.ts` | `../core/token.store` | `../../core/token.store` |

- [ ] **Step 3: Re-point the importers**

| File | New import path |
|---|---|
| `settings/backup-section.component.ts`, `settings/opml-section.component.ts` | `./opml-export` |
| `shared/marked-text/marked-text.component.ts` | `./search-marks` |
| `reader/entries.store.spec.ts` | `../shared/marked-text/search-marks` |
| `reader/reader-shell.component.ts` + `.spec.ts` | `SetupService` → `../core/setup.service`; `CatalogStore` → `./catalog/catalog.store`; `OnboardingSkip` → `./catalog/onboarding-skip` |
| `reader/entry-list/entry-list.component.ts` + `.spec.ts` | `CatalogStore` → `../catalog/catalog.store` |
| `settings/passkeys-group.component.ts` + `.spec.ts` | `../core/setup.service` |
| `auth/login/login.component.ts` + `.spec.ts`, `auth/reset-request/reset-request.component.ts` + `.spec.ts` | `../../core/setup.service` |
| `setup/setup.component.ts` + `.spec.ts` | `SetupApi` → `../core/setup-api`; `SetupService` → `../core/setup.service` |
| `setup/setup.guard.ts` + `setup/setup.guard.spec.ts` | `../core/setup.service` |
| `core/setup.service.spec.ts` | stays `./setup-api` and `./setup.service` (both moved together) |
| `core/auth.service.spec.ts`, `core/auth.interceptor.spec.ts` | `CatalogStore` → `../reader/catalog/catalog.store` |
| `discover/discover.component.ts` | `../reader/catalog/catalog-api`, `../reader/catalog/catalog.store`, `../reader/catalog/onboarding-skip` |
| `discover/discover.component.spec.ts` | `../reader/catalog/catalog.store`, `../reader/catalog/catalog.models` |
| `discover/catalog-selection.store.ts` + `.spec.ts`, `discover/category-rail.component.ts` + `.spec.ts`, `discover/category-chips.component.ts` | `../reader/catalog/catalog.models` |

Afterwards `grep -rn "discover/catalog\|discover/onboarding\|setup/setup.service\|setup/setup-api\|core/opml-export\|reader/search-marks" frontend/src` must print nothing.

- [ ] **Step 4: Specs may cross layers.** The two core specs check that logout resets `CatalogStore`, which is an integration concern. In `frontend/eslint.config.js`, add `"boundaries/dependencies": "off"` to the existing `**/*.spec.ts` override block. Then flip the main `boundaries/dependencies` entry from `"warn"` to `"error"`.

- [ ] **Step 5: Verify.** In the container, run `npx eslint "src/**/*.ts"` and expect zero `boundaries/dependencies` findings. Break-test it: add `import { ReaderApi } from '../reader/reader-api';` plus a use to `core/api.ts`, expect an **error**, then remove it by editing, not with `git checkout --`. Then run `npm run build` and `npm run check`.

- [ ] **Step 6: Commit, PR** (`Closes #1298`), merge when green.

```bash
git add -A frontend/src/app frontend/eslint.config.js
git commit -m "refactor(#1298): each module in the layer that owns it; boundaries rule is an error"
```
