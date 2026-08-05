# Boot Not Gated On i18n Implementation Plan (#280)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The app renders after a mobile resume-reload even when the i18n dictionary request fails or stalls; a bootstrap failure shows a static retry surface instead of a blank page.

**Architecture:** The English dictionary is bundled into the build and served by the Transloco loader without HTTP, so English can never fail to load and Transloco's own fallback chain terminates instantly. The boot-time dictionary preload becomes bounded (timeout) and non-fatal (falls back to bundled English, never rejects bootstrap). `main.ts` reveals a static retry element in `index.html` if bootstrap itself still rejects. The Strato `.htaccess` caches the dictionaries `immutable` — safe because the loader versions the URL with `?v=<release>` (#141).

**Tech Stack:** Angular 20 (standalone, signals), @jsverse/transloco 8, Jest (jsdom), Playwright, Apache `.htaccess`.

## Global Constraints

- Frontend lint gate is `npm run check` from `frontend/` (ESLint + Prettier 100-col + Stylelint + Jest). Run before every commit.
- No hex colours and no raw `px` in `.scss` outside `src/app/theme/`. (`index.html` is not linted by Stylelint; keep its inline style colour-free anyway so it follows the theme.)
- Component styles live in sibling `.scss` files — this plan adds no component styles.
- Comments explain *why*, never *what*; match the density and voice of the surrounding files.
- Commit messages follow the repo's convention: `fix(#280): <imperative summary>`.
- An e2e spec must own the data it asserts on (route stubbing), and leave fixtures as found.
- Verify each root cause claim against the running code, not memory.

**Background facts the implementer needs (verified 2026-08-05):**

- Transloco's `TranslocoService.load(path)` consults only its internal `cache` map (populated by prior `load` calls) — a `setTranslation()` call does **not** prevent an HTTP request. That is why the bundled English must live in the loader.
- With `missingHandler.useFallbackTranslation: true` and `fallbackLang: 'en'`, a failed `load('de')` falls back to `load('en')` internally and sets `en` active (`wasFailure` event). Once the loader serves `en` without HTTP, that chain can no longer hang or fail.
- A **stalled** (never-completing) request is not covered by that chain — `forkJoin` inside `load('de')` waits forever. That is what the `timeout` in the preload function is for.
- The reproduced bug: `provideAppInitializer` in `app.config.ts` returns `firstValueFrom(transloco.load(lang))`; when the request fails/stalls, the promise rejects/hangs, `bootstrapApplication` rejects, `main.ts` only logs, and `<app-root>` stays empty.

---

### Task 1: Bundle the English dictionary into the Transloco loader

**Files:**
- Modify: `frontend/tsconfig.json` (add `resolveJsonModule`)
- Modify: `frontend/src/app/core/language.ts` (add `FALLBACK_LANG`)
- Modify: `frontend/src/app/core/transloco-loader.ts`
- Test: `frontend/src/app/core/transloco-loader.spec.ts`

**Interfaces:**
- Consumes: `frontend/public/i18n/en.json` (exists, ~17 KB), `buildVersion` from `src/environments/version`.
- Produces: `FALLBACK_LANG: Lang` exported from `core/language.ts`; `HttpTranslocoLoader.getTranslation('en')` emits the bundled dictionary synchronously with no HTTP; any other lang keeps the existing HTTP request with the `?v=` param. Task 2 and Task 3 rely on `FALLBACK_LANG` and on `load('en')` being network-free.

- [ ] **Step 1: Enable JSON imports**

In `frontend/tsconfig.json`, inside the top-level `"compilerOptions"`, add:

```json
    "resolveJsonModule": true,
```

- [ ] **Step 2: Add `FALLBACK_LANG` to `core/language.ts`**

Append below `LANG_KEY`:

```ts
/**
 * The language every fallback path lands on: Transloco's `fallbackLang`, the
 * missing-key fallback translation, and the dictionary that ships inside the
 * bundle so booting can never depend on the network (#280).
 */
export const FALLBACK_LANG: Lang = 'en';
```

- [ ] **Step 3: Write the failing tests**

In `frontend/src/app/core/transloco-loader.spec.ts`, add to the existing `describe` (keep the two existing tests — they now describe the non-English path, so change their lang argument to `'de'` where it says `'en'`):

```ts
  // The English dictionary ships inside the bundle. Serving it from the loader
  // (not setTranslation: Transloco's load() consults only its own cache) means
  // the fallback chain terminates without the network, so a resume-reload on a
  // dead radio can no longer blank the app (#280).
  it('serves the bundled English dictionary without touching the network', (done) => {
    loader.getTranslation('en').subscribe((translation) => {
      // The real dictionary, not an empty object: the loader must not degrade
      // into serving `{}` and leaving every key to render as its raw name.
      const auth = translation['auth'] as { login: { subtitle: string } };
      expect(auth.login.subtitle).toBe('Welcome back to your reader.');
      done();
    });

    ctrl.expectNone((request) => request.url.includes('i18n'));
  });
```

The dictionaries are NESTED JSON, not flat dotted keys — `auth.login.subtitle` is
`{"auth": {"login": {"subtitle": "Welcome back to your reader."}}}` in
`frontend/public/i18n/en.json`. Transloco flattens on `setTranslation`; the loader
returns the raw nested object, so the assertion indexes it nested.

Also change the second existing test (`carries the build version…`) from `'en'` to `'de'`:

```ts
    loader.getTranslation('de').subscribe();

    const req = ctrl.expectOne(`i18n/de.json?v=${buildVersion.version}`);
    expect(req.request.params.get('v')).toBe(buildVersion.version);
    req.flush({});
```

- [ ] **Step 4: Run tests to verify the new one fails**

Run from `frontend/`: `npx jest src/app/core/transloco-loader.spec.ts`
Expected: the new test FAILS (an unexpected HTTP request for `i18n/en.json`); the two existing tests pass.

- [ ] **Step 5: Implement the loader change**

Replace the class body of `frontend/src/app/core/transloco-loader.ts` (keep the existing file header comment; extend it):

```ts
// src/app/core/transloco-loader.ts
import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Translation, TranslocoLoader } from '@jsverse/transloco';
import { Observable, of } from 'rxjs';
import { buildVersion } from '../../environments/version';
import { FALLBACK_LANG } from './language';
import englishDictionary from '../../../public/i18n/en.json';

/** Loads a language's dictionary — English from the bundle, the rest from the
 *  statically-served `public/i18n/`.
 *
 *  ENGLISH IS BUNDLED, not fetched. The dictionary preload used to gate the
 *  whole bootstrap on one uncached network request; a mobile browser that
 *  discards the tab and resume-reloads on a still-reconnecting radio got a
 *  permanently blank page (#280). With the fallback language compiled in, the
 *  fallback chain terminates without the network — and it has to live HERE,
 *  in the loader: Transloco's load() consults only its own request cache, so
 *  a setTranslation() call would not prevent the HTTP request.
 *
 *  The path is deliberately RELATIVE. The app is served at the domain root by
 *  the Docker setup and under a `/reader` subpath on Strato; a relative URL
 *  resolves against the document base URI, which `<base href>` sets per build,
 *  so one path is correct for both. A leading slash would 404 under a subpath.
 *
 *  The release version is deliberately in the QUERY STRING. The dictionaries
 *  sit at a path that never changes, so without it a cache may serve the
 *  previous release's copy and every key added since renders as its raw name
 *  (#141). Naming the version restores the same guarantee the hashed bundles
 *  have: a new release asks for a URL the cache has never held.
 */
@Injectable({ providedIn: 'root' })
export class HttpTranslocoLoader implements TranslocoLoader {
  private readonly http = inject(HttpClient);

  getTranslation(lang: string): Observable<Translation> {
    if (lang === FALLBACK_LANG) return of(englishDictionary);

    return this.http.get<Translation>(`i18n/${lang}.json`, {
      params: { v: buildVersion.version },
    });
  }
}
```

If TypeScript rejects `of(englishDictionary)` against `Observable<Translation>`, use `of(englishDictionary as Translation)` — `Translation` is Transloco's `HashMap`.

- [ ] **Step 6: Run the loader tests**

Run: `npx jest src/app/core/transloco-loader.spec.ts`
Expected: ALL PASS.

- [ ] **Step 7: Run the whole frontend gate**

Run from `frontend/`: `npm run check`
Expected: PASS (fix any Prettier line-length complaints in the touched files).

- [ ] **Step 8: Commit**

```bash
git add frontend/tsconfig.json frontend/src/app/core/language.ts frontend/src/app/core/transloco-loader.ts frontend/src/app/core/transloco-loader.spec.ts
git commit -m "fix(#280): bundle the English dictionary into the Transloco loader"
```

---

### Task 2: A bounded, non-fatal dictionary preload

**Files:**
- Create: `frontend/src/app/core/boot-language.ts`
- Test: `frontend/src/app/core/boot-language.spec.ts`

**Interfaces:**
- Consumes: `FALLBACK_LANG`, `Lang` from `./language` (Task 1); `TranslocoService` from `@jsverse/transloco`.
- Produces: `preloadInitialLanguage(transloco: TranslocoService, lang: Lang): Promise<unknown>` — resolves when the dictionary is ready, resolves with the bundled fallback after failure or `DICTIONARY_WAIT_MS`, and **never rejects**. Task 3 calls it from the app initializer.

- [ ] **Step 1: Write the failing tests**

Create `frontend/src/app/core/boot-language.spec.ts`:

```ts
// src/app/core/boot-language.spec.ts
import { NEVER, Observable, of, throwError } from 'rxjs';
import { TranslocoService } from '@jsverse/transloco';
import { DICTIONARY_WAIT_MS, preloadInitialLanguage } from './boot-language';
import { FALLBACK_LANG } from './language';

/** The two members preloadInitialLanguage touches, with observable behavior per lang. */
function translocoStub(load: (lang: string) => Observable<unknown>) {
  return {
    load: jest.fn(load),
    setActiveLang: jest.fn(),
  } as unknown as TranslocoService & { load: jest.Mock; setActiveLang: jest.Mock };
}

describe('preloadInitialLanguage', () => {
  afterEach(() => jest.useRealTimers());

  it('resolves once the requested dictionary loads', async () => {
    const transloco = translocoStub(() => of({ title: 'Anmelden' }));

    await expect(preloadInitialLanguage(transloco, 'de')).resolves.toEqual({ title: 'Anmelden' });
    expect(transloco.setActiveLang).not.toHaveBeenCalled();
  });

  it('falls back to the bundled language when the load fails', async () => {
    const transloco = translocoStub((lang) =>
      lang === FALLBACK_LANG ? of({}) : throwError(() => new Error('offline')),
    );

    await expect(preloadInitialLanguage(transloco, 'de')).resolves.toBeDefined();
    expect(transloco.setActiveLang).toHaveBeenCalledWith(FALLBACK_LANG);
    expect(transloco.load).toHaveBeenCalledWith(FALLBACK_LANG);
  });

  it('gives up on a stalled load after the wait bound and falls back', async () => {
    jest.useFakeTimers();
    const transloco = translocoStub((lang) => (lang === FALLBACK_LANG ? of({}) : NEVER));

    const boot = preloadInitialLanguage(transloco, 'de');
    jest.advanceTimersByTime(DICTIONARY_WAIT_MS);

    await expect(boot).resolves.toBeDefined();
    expect(transloco.setActiveLang).toHaveBeenCalledWith(FALLBACK_LANG);
  });

  it('never rejects, even when the fallback load itself throws', async () => {
    const transloco = translocoStub(() => throwError(() => new Error('everything is broken')));

    await expect(preloadInitialLanguage(transloco, 'de')).resolves.toBeUndefined();
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx jest src/app/core/boot-language.spec.ts`
Expected: FAIL — `./boot-language` does not exist.

- [ ] **Step 3: Implement**

Create `frontend/src/app/core/boot-language.ts`:

```ts
// src/app/core/boot-language.ts
import { TranslocoService } from '@jsverse/transloco';
import { catchError, firstValueFrom, timeout } from 'rxjs';
import { FALLBACK_LANG, Lang } from './language';

/**
 * How long boot waits for a network-loaded dictionary before falling back to
 * the bundled one. Long enough for a slow mobile round trip, short enough
 * that a resume-reload on a still-reconnecting radio shows English instead of
 * nothing (#280).
 */
export const DICTIONARY_WAIT_MS = 3000;

/**
 * Resolve the initial dictionary before the first render, without ever
 * holding the render hostage. The happy path keeps the original guarantee —
 * a German account does not flash English (#141's initializer) — but failure
 * and stall now land on the bundled fallback language instead of rejecting
 * `bootstrapApplication`, which left `<app-root>` permanently empty.
 *
 * The final catch is defense in depth: the fallback load is network-free
 * (see transloco-loader.ts) and should not fail, but a blank page is the one
 * outcome this function exists to make impossible.
 */
export function preloadInitialLanguage(
  transloco: TranslocoService,
  lang: Lang,
): Promise<unknown> {
  return firstValueFrom(
    transloco.load(lang).pipe(
      timeout(DICTIONARY_WAIT_MS),
      catchError(() => {
        transloco.setActiveLang(FALLBACK_LANG);
        return transloco.load(FALLBACK_LANG);
      }),
    ),
  ).catch(() => undefined);
}
```

- [ ] **Step 4: Run the tests**

Run: `npx jest src/app/core/boot-language.spec.ts`
Expected: ALL PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/core/boot-language.ts frontend/src/app/core/boot-language.spec.ts
git commit -m "fix(#280): add a bounded, non-fatal dictionary preload"
```

---

### Task 3: Wire the preload into the app initializer

**Files:**
- Modify: `frontend/src/app/app.config.ts`

**Interfaces:**
- Consumes: `preloadInitialLanguage` (Task 2), `FALLBACK_LANG`, `LANGS` from `core/language` (Task 1).
- Produces: an app initializer that never rejects; Transloco config driven by the shared constants.

- [ ] **Step 1: Replace the initializer and de-duplicate the language literals**

In `frontend/src/app/app.config.ts`:

Add imports:

```ts
import { preloadInitialLanguage } from './core/boot-language';
import { FALLBACK_LANG, LANGS } from './core/language';
```

Drop the now-unused `firstValueFrom` import from `rxjs` (keep the line only if something else still uses it — nothing should).

Change the Transloco config lines to use the constants (behaviour-identical, but the fallback language is now named once, in `core/language.ts`):

```ts
      config: {
        availableLangs: [...LANGS],
        defaultLang: FALLBACK_LANG,
        fallbackLang: FALLBACK_LANG,
        reRenderOnLangChange: true,
        prodMode: !isDevMode(),
        missingHandler: { logMissingKey: isDevMode(), useFallbackTranslation: true },
      },
```

Replace the initializer block (comment included — it documents the new contract):

```ts
    // Resolve the persisted/detected language and preload its dictionary before
    // the first render, so the app never flashes English before switching to
    // German. Bounded and non-fatal (#280): a failed or stalled dictionary
    // request falls back to the bundled English instead of rejecting bootstrap
    // and leaving a permanently blank page.
    provideAppInitializer(() => {
      const language = inject(LanguageService); // constructing it sets the active lang
      const transloco = inject(TranslocoService);
      return preloadInitialLanguage(transloco, language.lang());
    }),
```

- [ ] **Step 2: Run the full unit suite and the gate**

Run from `frontend/`: `npm run check`
Expected: PASS (this compiles `app.config.ts` and runs the whole Jest suite including `app.config.spec.ts`).

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/app.config.ts
git commit -m "fix(#280): boot never rejects on a failed dictionary preload"
```

---

### Task 4: A static failure surface for a rejected bootstrap

**Files:**
- Modify: `frontend/src/index.html`
- Modify: `frontend/src/main.ts`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: `#boot-error` element in `index.html`, revealed by `main.ts` on bootstrap rejection.

- [ ] **Step 1: Add the hidden element to `index.html`**

In `frontend/src/index.html`, replace `<app-root></app-root>` inside `<body>` with:

```html
    <app-root></app-root>
    <!--
      Revealed by main.ts only when bootstrapApplication rejects. Static and
      style-inlined on purpose: at that point no bundle code is trustworthy,
      so this surface must depend on nothing but the document itself (#280).
      Both languages, because no dictionary loaded either.
    -->
    <div id="boot-error" hidden style="padding: 2rem; text-align: center; font-family: system-ui">
      <p>The app could not start. / Die App konnte nicht gestartet werden.</p>
      <button onclick="location.reload()">Reload / Neu laden</button>
    </div>
```

- [ ] **Step 2: Reveal it from `main.ts`**

Replace the bootstrap call in `frontend/src/main.ts`:

```ts
bootstrapApplication(App, appConfig).catch((err) => {
  console.error(err);
  // The initializer never rejects (core/boot-language.ts), so reaching this
  // means the platform itself failed to come up. A console line helps nobody
  // on a phone; give the user something to act on instead of a blank page.
  document.getElementById('boot-error')?.removeAttribute('hidden');
});
```

- [ ] **Step 3: Verify by hand in the dev server**

With the Docker stack up and `npm start` running, load `http://localhost:4200/` and confirm the app still renders normally and the error element stays hidden (inspect: `#boot-error` present with `hidden`).

- [ ] **Step 4: Run the gate**

Run from `frontend/`: `npm run check`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/index.html frontend/src/main.ts
git commit -m "fix(#280): show a static retry surface when bootstrap rejects"
```

---

### Task 5: Cache the dictionaries on Strato

**Files:**
- Modify: `deploy/strato/.htaccess`

**Interfaces:**
- Consumes: the loader's `?v=<release>` query param (pre-existing, #141).
- Produces: `Cache-Control: public, max-age=31536000, immutable` for `en.json`/`de.json`.

- [ ] **Step 1: Add the header rule**

In `deploy/strato/.htaccess`, inside the existing `<IfModule mod_headers.c>` block, after the hashed-assets `<FilesMatch>` rule, add:

```apache
    # The dictionaries keep their names across releases, but the app requests
    # them with ?v=<release> (#141), so every release is a URL no cache has
    # already held -- the same guarantee the hashed bundles get from their
    # names. Long caching then takes the one boot-critical fetch off the
    # network on a mobile resume-reload (#280). Scoped to the two dictionary
    # files, not *.json, so nothing else is accidentally pinned for a year.
    <FilesMatch "^(en|de)\.json$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
```

Also update the comment on the hashed-assets rule above it: it currently lists "i18n dictionaries" among the deliberately-excluded unhashed files; reword that parenthetical to `(index.html above, favicons)` and no more, since the dictionaries are now covered by their own rule.

- [ ] **Step 2: Verify Apache syntax locally**

Run: `apachectl configtest 2>/dev/null || echo "no local apachectl - visual review only"`
`.htaccess` cannot be config-tested standalone; a careful visual diff against the existing `<FilesMatch>` block (same quoting, same directive) is the check here.

- [ ] **Step 3: Commit**

```bash
git add deploy/strato/.htaccess
git commit -m "fix(#280): cache the versioned i18n dictionaries immutably on Strato"
```

---

### Task 6: E2e proof — the app renders without a reachable dictionary

**Files:**
- Create: `frontend/e2e/boot-without-dictionary.spec.ts`

**Interfaces:**
- Consumes: the dev server on `http://localhost:4200` (Playwright `webServer` config starts/reuses it), the Docker stack for the API, `LANG_KEY` = `'sfr.lang'`.
- Produces: two Playwright tests proving the #280 failure modes render.

The exact copy, already resolved — do not go looking for it:
`auth.login.subtitle` is `"Welcome back to your reader."` in `en.json` and
`"Willkommen zurück bei deinem Reader."` in `de.json`. Both appear on `/login`.
Asserting the English string is visible AND the German one is not proves the
fallback actually engaged, rather than the device language never applying.

- [ ] **Step 1: Write the spec**

Create `frontend/e2e/boot-without-dictionary.spec.ts`:

```ts
// e2e/boot-without-dictionary.spec.ts
import { test, expect, Page } from '@playwright/test';

/**
 * #280: a mobile browser discards the backgrounded tab and resume-reloads on a
 * radio that is still reconnecting. Boot used to gate on the dictionary fetch,
 * so a failed or stalled request left a permanently blank page. The app must
 * now render — in the bundled English fallback — in both failure modes.
 *
 * The device persisted German, so boot genuinely needs the network-loaded
 * dictionary; English alone would never issue the request (it is bundled).
 * The spec owns all the data it asserts on: no account, no seeded state.
 */
const ENGLISH_SUBTITLE = 'Welcome back to your reader.';
const GERMAN_SUBTITLE = 'Willkommen zurück bei deinem Reader.';

async function bootAsGermanDevice(page: Page) {
  await page.addInitScript(() => localStorage.setItem('sfr.lang', 'de'));
}

/** The app rendered, and it rendered via the bundled fallback. */
async function expectFallbackRender(page: Page) {
  await expect(page.getByText(ENGLISH_SUBTITLE)).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText(GERMAN_SUBTITLE)).toHaveCount(0);
}

test('renders the login screen when the dictionary request fails', async ({ page }) => {
  await bootAsGermanDevice(page);
  await page.route('**/i18n/*.json*', (route) => route.abort('failed'));

  await page.goto('/login');

  await expectFallbackRender(page);
});

test('renders the login screen when the dictionary request stalls', async ({ page }) => {
  await bootAsGermanDevice(page);
  // Neither fulfilled nor aborted: the request hangs, like a dead radio.
  await page.route('**/i18n/*.json*', () => undefined);

  await page.goto('/login');

  await expectFallbackRender(page);
});
```

- [ ] **Step 3: Run the spec (Docker stack must be up)**

Run from `frontend/`: `npx playwright test e2e/boot-without-dictionary.spec.ts`
Expected: both tests PASS. (Before Tasks 1–3 they would fail — if you want the red leg first, run this spec before implementing Task 1.)

- [ ] **Step 4: Run the existing e2e smokes to catch regressions**

Run from `frontend/`: `npm run e2e`
Expected: PASS (known-flaky exceptions aside; anything newly failing must be investigated).

- [ ] **Step 5: Commit**

```bash
git add frontend/e2e/boot-without-dictionary.spec.ts
git commit -m "fix(#280): e2e-prove boot renders without a reachable dictionary"
```

---

### Task 7: Full verification and PR

**Files:** none new.

- [ ] **Step 1: Full frontend gate**

Run from `frontend/`: `npm run check` — PASS required.

- [ ] **Step 2: Production build**

Run from `frontend/`: `npm run build` — must succeed (this is where a broken JSON import or `resolveJsonModule` slip would surface outside Jest).

- [ ] **Step 3: Backend untouched — sanity only**

No backend files change in this plan. `git status` must show no `backend/` modifications.

- [ ] **Step 4: Push and open the PR**

```bash
git push -u origin fix/280-boot-not-gated-on-i18n
gh pr create --base develop --title "fix(#280): never gate boot on the i18n dictionary fetch" --body "Closes #280

- Bundle the English dictionary into the Transloco loader (no HTTP for the fallback language)
- Bounded, non-fatal boot preload: timeout + fallback instead of rejecting bootstrapApplication
- Static bilingual retry surface in index.html for a rejected bootstrap
- Cache the ?v=-versioned dictionaries immutably on Strato
- Playwright spec covering both failure modes (failed and stalled dictionary request)"
```

- [ ] **Step 5: Verify CI is green on the PR before asking for merge**

Check the run conclusion by run id (`gh run list --branch fix/280-boot-not-gated-on-i18n`), not `gh run watch --exit-status` — its exit status lies.
