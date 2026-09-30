# Frontend lint baseline — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (inline). Steps use checkbox (`- [ ]`) syntax.

**Goal:** `npm run check` enforces the backend's mechanical standards in the frontend: size and complexity limits, import direction, full names, and no HttpClient in components (#1297).

**Architecture:** One config file, `frontend/eslint.config.js`. Rules the tree already satisfies are `error`. Rules it still violates are `warn`; `ng lint` shows warnings but does not fail on them. Each later issue in the series flips its own rules to `error` (see the ratchet table). No custom rules. The 3-line comment limit becomes a CLAUDE.md recommendation, not a rule.

**Tech Stack:** ESLint 9 flat config, angular-eslint 21, typescript-eslint 8, plus the new `eslint-plugin-boundaries`, `eslint-plugin-unicorn` and `eslint-plugin-sonarjs`.

## Global Constraints

- Branch `chore/1297-frontend-lint-baseline` off `develop`. Commit format `type(#1297): summary`. First commit copies this plan to `docs/superpowers/plans/`.
- Install dependencies inside the frontend container **and** on the host so `package-lock.json` changes once. Then clear the Angular/ESLint caches (memory: new frontend dep needs a container install and a cache clear).
- Gate: `docker compose exec -T frontend npm run check`.
- Keep it lean. Don't fix warnings in this issue; the later issues own them.

## Ratchet table (the later issues flip these to `error`)

| Rule | Flipped by |
|---|---|
| `boundaries/dependencies` | #1298 |
| `no-restricted-imports` (HttpClient in components) | #1300 |
| `max-lines`, `max-lines-per-function`, `complexity`, `sonarjs/cognitive-complexity`, `@angular-eslint/template/cyclomatic-complexity` | #1303 (only those that reach 0; otherwise keep `warn` and record the count) |
| `sonarjs/no-identical-functions` | #1299 |
| `unicorn/prevent-abbreviations`, `id-length` | #1304 |

---

### Task 1: Add the rules

**Files:**
- Modify: `frontend/package.json`, `frontend/package-lock.json`, `frontend/eslint.config.js`

- [ ] **Step 1: Install**

```bash
docker compose exec -T frontend npm install --save-dev eslint-plugin-boundaries@^7.2.0 eslint-plugin-unicorn@^76.0.0 eslint-plugin-sonarjs@^4.2.2
```

- [ ] **Step 2: Replace `frontend/eslint.config.js`** with the following. unicorn is ESM-only. If `require` fails under the container's Node, rename the file to `eslint.config.mjs` and convert the requires to `import` statements; nothing else changes.

```js
// @ts-check
const eslint = require("@eslint/js");
const { defineConfig } = require("eslint/config");
const tseslint = require("typescript-eslint");
const angular = require("angular-eslint");
const prettier = require("eslint-config-prettier");
const boundaries = require("eslint-plugin-boundaries");
const unicorn = require("eslint-plugin-unicorn");
const sonarjs = require("eslint-plugin-sonarjs");

const FEATURE_IMPORTS = {
  core: ["core", "theme"],
  shared: ["core", "shared", "theme"],
  theme: ["theme"],
  reader: ["core", "shared", "theme", "reader"],
  settings: ["core", "shared", "theme", "settings", "reader"],
  admin: ["core", "shared", "theme", "admin", "reader"],
  discover: ["core", "shared", "theme", "discover", "reader"],
  auth: ["core", "shared", "theme", "auth"],
  setup: ["core", "shared", "theme", "setup", "auth"],
};

module.exports = defineConfig([
  {
    files: ["**/*.ts"],
    extends: [
      eslint.configs.recommended,
      tseslint.configs.recommended,
      tseslint.configs.stylistic,
      angular.configs.tsRecommended,
      prettier,
    ],
    processor: angular.processInlineTemplates,
    plugins: { boundaries, unicorn, sonarjs },
    settings: {
      "import/resolver": { node: { extensions: [".ts", ".js"] } },
      "boundaries/elements": Object.keys(FEATURE_IMPORTS).map((type) => ({
        type,
        pattern: `src/app/${type}`,
      })),
    },
    rules: {
      "@angular-eslint/directive-selector": [
        "error",
        { type: "attribute", prefix: "app", style: "camelCase" },
      ],
      "@angular-eslint/component-selector": [
        "error",
        { type: "element", prefix: "app", style: "kebab-case" },
      ],
      "boundaries/dependencies": [
        "warn",
        {
          default: "disallow",
          policies: Object.entries(FEATURE_IMPORTS).map(([from, to]) => ({
            from: { element: { type: from } },
            allow: { to: { element: { types: { anyOf: to } } } },
          })),
        },
      ],
      "max-lines": ["warn", { max: 300, skipBlankLines: true, skipComments: true }],
      "max-lines-per-function": ["warn", { max: 60, skipBlankLines: true, skipComments: true }],
      complexity: ["warn", 10],
      "@typescript-eslint/max-params": ["warn", { max: 3 }],
      "sonarjs/cognitive-complexity": ["warn", 15],
      "sonarjs/no-identical-functions": "warn",
      "id-length": ["warn", { min: 2, exceptions: ["x", "y", "_"], properties: "never" }],
      "unicorn/prevent-abbreviations": [
        "warn",
        {
          checkFilenames: false,
          checkProperties: false,
          replacements: {
            svc: { service: true },
            recs: { recommendations: true },
            subs: { subscriptions: true },
            hdr: { header: true },
            grp: { group: true },
          },
        },
      ],
    },
  },
  {
    files: ["src/app/**/*.component.ts", "src/app/**/*.store.ts"],
    rules: {
      "no-restricted-imports": [
        "warn",
        {
          paths: [
            {
              name: "@angular/common/http",
              importNames: ["HttpClient"],
              message: "Call HTTP through the feature's *-api.ts file (#1300).",
            },
          ],
        },
      ],
    },
  },
  {
    files: ["**/*.spec.ts"],
    rules: {
      "max-lines": "off",
      "max-lines-per-function": "off",
      "sonarjs/no-identical-functions": "off",
    },
  },
  {
    files: ["**/*.html"],
    extends: [angular.configs.templateRecommended, angular.configs.templateAccessibility],
    rules: {
      "@angular-eslint/template/cyclomatic-complexity": ["warn", { maxComplexity: 25 }],
    },
  },
]);
```

- [ ] **Step 3: Prove the boundaries guard works by breaking it.** Add `import { ReaderApi } from '../reader/reader-api';` plus a use to `src/app/shared/spinner/spinner.component.ts`. Temporarily set `boundaries/dependencies` to `error`, run `docker compose exec -T frontend npx eslint src/app/shared/spinner/spinner.component.ts`, and expect a `boundaries/dependencies` error. Remove the import without using `git checkout --` (see memory). Expect the known edges and nothing else. That is about 7 files: `core/opml-export.ts`→reader, `shared/marked-text`→reader, reader→setup and reader→discover (reader-shell, entry-list), and auth→setup (login, reset-request). If you see 0 or hundreds, the element patterns or the resolver are wrong. Fix the pattern (try `app/<type>`), not the matrix.

- [ ] **Step 4: Ratchet.** Run `docker compose exec -T frontend npx eslint "src/**/*.ts" "src/**/*.html" -f json -o /tmp/lint.json` and count warnings per rule. Every rule with 0 warnings becomes `"error"`. Write the per-rule counts into the PR body.

- [ ] **Step 5: CLAUDE.md, under "Frontend conventions".** Add two bullets:

```markdown
- **ESLint mirrors the backend gates** (`eslint.config.js`): layer matrix
  (`boundaries/dependencies`), size/complexity limits, full names
  (`unicorn/prevent-abbreviations`, `id-length`), no `HttpClient` in components.
  Rules still at `warn` are a ratchet — flip to `error` when the tree is clean, never back.
- **Comments follow the PHP comment rules above** (recommended length, no issue
  numbers or change history); no lint rule enforces them.
```

- [ ] **Step 6: Gate, commit, PR** (`Closes #1297`, per-rule counts in the body), merge when green.

```bash
git add frontend/package.json frontend/package-lock.json frontend/eslint.config.js CLAUDE.md
git commit -m "chore(#1297): lint baseline mirroring the backend gates"
```
