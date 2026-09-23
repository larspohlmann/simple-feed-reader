# Kicker-line container query interpolation (#1132) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the kicker line's narrow time form ("52d ago") actually apply below
`bp.$container-kicker-narrow`, and make a bare Sass variable in any `@container`
prelude a Stylelint failure so the defect cannot return.

**Architecture:** One-token SCSS fix (`#{…}` interpolation), pinned by a new
Playwright case that measures each kicker line's own width on the real render
and asserts the matching time form is the visible one. A local Stylelint plugin
walks `@container` at-rules and rejects any `$variable` outside `#{…}`.

**Tech Stack:** Angular 20, Dart Sass, Stylelint 17 (ESM plugin API), Playwright.

**Spec:** GitHub issue #1132 (no separate design doc — the issue's "Direction"
section is the spec).

## Global Constraints

- Branch: `fix/1132-kicker-container-interpolation` off `develop`; PR into `develop`, body says `Closes #1132`.
- Commit format: `type(#1132): …`, no attribution lines.
- Hex colours, ad-hoc `px`, media-query literals are forbidden in `.scss` outside `src/app/theme/`.
- Comments: default to none; one line, three at the most.
- `npm run check` (from `frontend/`) is the CI gate; run frontend Jest inside the Docker `frontend` container.
- e2e runs only from this checkout (it owns the Docker stack); the spec must own its data (stub routes).

## Facts measured before planning (real render, current `develop`)

Stubbed entries with a 320×200 image become `app-entry-split` / `app-entry-thumb`
blocks. Kicker line widths (17rem = 272px):

| Viewport | split kicker | thumb kicker | visible form today |
|---|---|---|---|
| 1280×900 | 393.48px | 554px | wide (correct) |
| 768×1024 | 254.61px | 330px | wide — **split should be narrow** |
| 375×812 | 189.5px | 225px | wide — **both should be narrow** |

So 768 gives one card of each form in the same page; 1280 gives all-wide.

The rule's history: `73c8614b` (#769) added it as `@container (width < 17rem)`,
which worked; `fd47a03e` (#769, 47 minutes later) tokenized it to the bare
`bp.$container-kicker-narrow`, which silently disabled it. Intent is unchanged:
below 17rem the time takes its narrow form so `.source` keeps the space.

`docs/design-language.md` already says to interpolate (added in #1127); it only
needs the "which has this defect" reference to #1132 retired.

---

### Task 1: Interpolate the threshold, pinned by a real-render e2e case

**Files:**
- Modify: `frontend/e2e/magazine-kicker-one-line.spec.ts` (add an image entry set + one test)
- Modify: `frontend/src/app/reader/magazine/entry-kicker-line.component.scss:62`
- Modify: `docs/design-language.md:204-207`

**Interfaces:**
- Consumes: existing `entry()`, `signInAsAdmin()` and `resizeTo()` helpers in the spec; `signInAsAdmin` stubs `/api/entries` with `ENTRIES`.
- Produces: nothing later tasks call.

- [ ] **Step 1: Write the failing e2e case**

`signInAsAdmin` hard-wires `ENTRIES`. Give `stubEntries` and `signInAsAdmin` an
`entries` parameter defaulting to `ENTRIES` (no boolean flag), then add below the
first test:

```ts
/** A 300–399px-wide image fits `split` but not `wide`/`hero`, so these render as
 *  split and thumb cards — the blocks whose text column crosses the threshold. */
const SPLIT_IMAGE = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

const SPLIT_ENTRIES = ENTRIES.map((each) => ({
  ...each,
  imageUrl: SPLIT_IMAGE,
  imageWidth: 320,
  imageHeight: 200,
}));

/** 17rem, `$container-kicker-narrow` in `theme/_breakpoints.scss`. */
const KICKER_NARROW_PX = 17 * 16;

test('the time takes its narrow form exactly when the kicker line is narrow', async ({ page }) => {
  const signedIn = await signInAsAdmin(page, SPLIT_ENTRIES);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  // 768 puts a split card below the threshold and a thumb card above it on one page.
  await resizeTo(page, { width: 768, height: 1024 });
  const lines = page.locator('app-entry-kicker-line');
  await expect(lines.first()).toBeVisible();

  const forms = await lines.evaluateAll((rows) =>
    rows.map((row) => ({
      width: row.getBoundingClientRect().width,
      wideShown: getComputedStyle(row.querySelector('.when-wide')!).display !== 'none',
      narrowShown: getComputedStyle(row.querySelector('.when-narrow')!).display !== 'none',
    })),
  );

  const narrow = forms.filter(({ width }) => width < KICKER_NARROW_PX);
  const wide = forms.filter(({ width }) => width >= KICKER_NARROW_PX);
  expect(narrow.length, 'no kicker line below the threshold — the case proves nothing').toBeGreaterThan(0);
  expect(wide.length, 'no kicker line above the threshold — the case proves nothing').toBeGreaterThan(0);
  expect(narrow.every(({ wideShown, narrowShown }) => !wideShown && narrowShown)).toBe(true);
  expect(wide.every(({ wideShown, narrowShown }) => wideShown && !narrowShown)).toBe(true);
});
```

- [ ] **Step 2: Run it and confirm it fails for the right reason**

Run (from `frontend/`): `npx playwright test e2e/magazine-kicker-one-line.spec.ts --reporter=line`
Expected: the new case FAILS on the `narrow.every(...)` assertion (both counts
are > 0; the split lines at ~254px still show the wide form). The three existing
cases PASS. If a count assertion fails instead, the fixture does not produce
split cards — fix the fixture, not the threshold.

- [ ] **Step 3: Interpolate the threshold**

`frontend/src/app/reader/magazine/entry-kicker-line.component.scss:62`:

```scss
@container (width < #{bp.$container-kicker-narrow}) {
```

- [ ] **Step 4: Confirm the frontend container serves the change, then re-run**

`docker compose logs --since 2m frontend | tail` must show a rebuild after the
edit (otherwise `docker compose restart frontend`). Then run the command from
Step 2. Expected: all four cases PASS.

- [ ] **Step 5: Look at the real render**

Take screenshots at 375 and 768 with the split fixture (a throwaway spec in the
scratchpad, not committed), and Read them: split kicker lines show "52d ago",
and the source name keeps more characters than before ("NDR.de…" today at 375).

- [ ] **Step 6: Retire the "has this defect" note**

`docs/design-language.md`, end of the list-header paragraph — replace
`(see #1132 for the kicker line, which has this defect).` with `(#1132).`

- [ ] **Step 7: Gate and commit**

Run: `npm run format:check && npm run stylelint` (from `frontend/`). Expected: clean.

```bash
git add frontend/src/app/reader/magazine/entry-kicker-line.component.scss frontend/e2e/magazine-kicker-one-line.spec.ts docs/design-language.md
git commit -m "fix(#1132): interpolate the kicker-line container threshold so the narrow time applies"
```

Commit body: the rule worked as a literal in 73c8614b and died when fd47a03e
tokenized it; Dart Sass leaves a bare variable in an `@container` prelude as-is.

---

### Task 2: Stylelint guard against a bare variable in an `@container` prelude

**Files:**
- Create: `frontend/stylelint/no-bare-variable-in-container-prelude.mjs`
- Modify: `frontend/.stylelintrc.json` (add `plugins` and the rule)
- Modify: `docs/design-language.md` (one sentence: Stylelint enforces the interpolation)

**Interfaces:**
- Consumes: Stylelint 17 `createPlugin`, `utils.report`, `utils.ruleMessages`.
- Produces: rule `local/no-bare-variable-in-container-prelude`.

The plugin was prototyped against this tree before planning: on `develop` it
reports exactly `entry-kicker-line.component.scss 62:21` and passes the four
interpolated list-header preludes.

- [ ] **Step 1: Write the plugin**

```js
import stylelint from 'stylelint';

const {
  createPlugin,
  utils: { report, ruleMessages },
} = stylelint;

const ruleName = 'local/no-bare-variable-in-container-prelude';

const messages = ruleMessages(ruleName, {
  rejected: (variable) =>
    `Interpolate "${variable}" as "#{${variable}}": Sass leaves a bare variable in an @container prelude unresolved`,
});

const INTERPOLATION = /#\{[^}]*\}/g;
const VARIABLE = /(?:[\w-]+\.)?\$[\w-]+/g;

const rule = (enabled) => (root, result) => {
  if (!enabled) return;
  root.walkAtRules('container', (atRule) => {
    const bareParams = atRule.params.replace(INTERPOLATION, (match) => ' '.repeat(match.length));
    for (const [variable] of bareParams.matchAll(VARIABLE)) {
      report({ result, ruleName, node: atRule, word: variable, message: messages.rejected(variable) });
    }
  });
};

rule.ruleName = ruleName;
rule.messages = messages;

export default createPlugin(ruleName, rule);
```

The interpolations are blanked to spaces, not removed, so `word` positions stay
true to the source.

- [ ] **Step 2: Register it**

`frontend/.stylelintrc.json`: add `"plugins": ["./stylelint/no-bare-variable-in-container-prelude.mjs"]`
after `extends`, and `"local/no-bare-variable-in-container-prelude": true` to `rules`.
It applies everywhere, including `theme/` (no override entry).

- [ ] **Step 3: Green on the fixed tree**

Run (from `frontend/`): `npm run stylelint`. Expected: exit 0.

- [ ] **Step 4: Break what it guards — both directions**

1. Edit line 62 of the kicker scss back to `bp.$container-kicker-narrow` with
   the Edit tool. Run `npm run stylelint`. Expected: exit 2, one error at
   `entry-kicker-line.component.scss 62:21` naming `bp.$container-kicker-narrow`.
   Restore with the Edit tool (not `git checkout --`).
2. Change one list-header prelude to a non-namespaced bare variable
   (`width <= $x`) in `src/styles/_list-action.scss:58`. Expected: an error
   naming `$x`. Restore with the Edit tool.
3. `git diff --stat -- frontend/src` shows no change; `npm run stylelint` exits 0.

- [ ] **Step 5: Document**

`docs/design-language.md`, after the interpolation sentence (the `(#1132).`
from Task 1): `Stylelint's local/no-bare-variable-in-container-prelude rejects the bare form.`

- [ ] **Step 6: Full gate and commit**

Run: `docker compose exec -T frontend npm run check`. Expected: PASS.

```bash
git add frontend/stylelint/no-bare-variable-in-container-prelude.mjs frontend/.stylelintrc.json docs/design-language.md
git commit -m "build(#1132): reject a bare Sass variable in an @container prelude"
```

---

### Task 3: PR

- [ ] Push the branch, open a PR into `develop` with `Closes #1132`, the before/after
  kicker widths table and the before/after 375px screenshots described in prose.
- [ ] Read CI with the ccd_pr tools; do not merge.
