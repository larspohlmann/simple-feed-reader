# #1638 Escape slashes past the host's content filter: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Requests carrying a Bluesky or Mastodon URL get past Strato's content filter and reach the app. When the filter still answers, the user sees a message that names the cause.

**Architecture:** A functional Angular interceptor serialises every JSON request body itself and writes `/` as `\/`. That is valid, standard JSON, and the filter does not match it. `parseProblem()` gives an HTML page under a 2xx status its own wording, because only a host-level interceptor produces one. The backend does not change.

**Tech Stack:** Angular 20 (standalone, functional interceptors), Jest with `HttpTestingController`.

**Spec:** `docs/superpowers/specs/2026-10-16-1638-social-url-firewall-design.md`

## Global Constraints

- Frontend only. No backend change and no API change.
- Only bodies for which `request.detectContentTypeHeader() === 'application/json'` are rewritten. Strings, `Blob`, `FormData`, `ArrayBuffer`, `URLSearchParams`, `HttpParams` and null bodies pass through untouched.
- A Content-Type that the caller set explicitly is kept. Otherwise the interceptor sets `application/json`.
- Message for an HTML page under 2xx: `The web host blocked this request before it reached the app (it answered "<title>").` Non-2xx HTML keeps `The web server answered "<title>" instead of the app. Try again in a minute.`
- Commit format `type(#1638): lower-case summary`. No Claude attribution.
- Comments: default to none, one line at most (CLAUDE.md).
- Run frontend tooling from `frontend/`. The worktree has no `node_modules`, so symlink the main checkout's first (Task 1, Step 0). Use `./node_modules/.bin/jest`, never `npx jest`.
- Deferred, NOT in this plan: image-proxy query encoding, OPML entity escaping, backup re-serialisation (spec §3, waiting on a prod probe).

---

### Task 1: `escapeJsonSlashesInterceptor`

**Files:**
- Create: `frontend/src/app/core/http/escape-json-slashes.ts`
- Create: `frontend/src/app/core/http/escape-json-slashes.spec.ts`
- Modify: `frontend/src/app/app.config.ts` (import, plus one entry in `withInterceptors([...])`)
- Modify: `docs/architecture.md` §3 (one invariant row)

**Interfaces:**
- Produces: `export const escapeJsonSlashesInterceptor: HttpInterceptorFn`

- [ ] **Step 0: Make the toolchain available in the worktree**

```bash
ln -s /Users/lars/Documents/work/eigenes/simple-feed-reader/frontend/node_modules frontend/node_modules
```

`node_modules` is gitignored, so the symlink is never committed.

- [ ] **Step 1: Write the failing spec** (`frontend/src/app/core/http/escape-json-slashes.spec.ts`)

```ts
import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { escapeJsonSlashesInterceptor } from './escape-json-slashes';

describe('escapeJsonSlashesInterceptor', () => {
  let http: HttpClient;
  let backend: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([escapeJsonSlashesInterceptor])),
        provideHttpClientTesting(),
      ],
    });
    http = TestBed.inject(HttpClient);
    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => backend.verify());

  it('writes every slash in a JSON body as \\/ and sends it as JSON', () => {
    http.post('/api/subscriptions', { url: 'https://bsky.app/profile/x/rss' }).subscribe();

    const sent = backend.expectOne('/api/subscriptions').request;
    expect(sent.body).toBe('{"url":"https:\\/\\/bsky.app\\/profile\\/x\\/rss"}');
    expect(sent.headers.get('Content-Type')).toBe('application/json');
  });

  it('sends a body that parses back to the original, a literal backslash before a slash included', () => {
    const body = { path: 'a\\/b', feeds: [{ url: 'https://mastodon.social/@x.rss' }] };
    http.put('/api/feeds', body).subscribe();

    const sent = backend.expectOne('/api/feeds').request;
    expect(JSON.parse(sent.body as string)).toEqual(body);
  });

  it('keeps a Content-Type the caller named', () => {
    http
      .patch('/api/me', { avatar: 'https://bsky.social/a.png' }, {
        headers: { 'Content-Type': 'application/merge-patch+json' },
      })
      .subscribe();

    const sent = backend.expectOne('/api/me').request;
    expect(sent.headers.get('Content-Type')).toBe('application/merge-patch+json');
    expect(sent.body).toBe('{"avatar":"https:\\/\\/bsky.social\\/a.png"}');
  });

  it.each([
    ['an XML string', '<outline xmlUrl="https://bsky.app/x/rss"/>'],
    ['a Blob', new Blob(['{"url":"https://bsky.app/x"}'])],
    ['FormData', new FormData()],
  ])('leaves %s untouched', (_kind, body) => {
    http.post('/api/opml/import', body).subscribe();

    expect(backend.expectOne('/api/opml/import').request.body).toBe(body);
  });

  it('leaves a bodiless GET untouched', () => {
    http.get('/api/subscriptions').subscribe();

    const sent = backend.expectOne('/api/subscriptions').request;
    expect(sent.body).toBeNull();
    expect(sent.headers.has('Content-Type')).toBe(false);
  });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run (from `frontend/`): `./node_modules/.bin/jest src/app/core/http/escape-json-slashes.spec.ts`
Expected: FAIL, `Cannot find module './escape-json-slashes'`.

- [ ] **Step 3: Implement** (`frontend/src/app/core/http/escape-json-slashes.ts`)

```ts
import { HttpInterceptorFn } from '@angular/common/http';

/** The web host's content filter drops a body holding a raw social-network URL but misses `\/` (#1638). */
export const escapeJsonSlashesInterceptor: HttpInterceptorFn = (request, next) => {
  if (request.detectContentTypeHeader() !== 'application/json') return next(request);

  return next(
    request.clone({
      body: JSON.stringify(request.body).replaceAll('/', '\\/'),
      setHeaders: request.headers.has('Content-Type') ? {} : { 'Content-Type': 'application/json' },
    }),
  );
};
```

- [ ] **Step 4: Run the spec and watch it pass**

Run: `./node_modules/.bin/jest src/app/core/http/escape-json-slashes.spec.ts`
Expected: PASS, 7 tests.

- [ ] **Step 5: Break-test the guard.** Temporarily change `replaceAll('/', '\\/')` to `replaceAll('/', '/')`, rerun, and confirm the first and third tests FAIL. Then restore the line by editing it back. Do not use `git checkout --`.

- [ ] **Step 6: Register it** in `frontend/src/app/app.config.ts`

Add the import after the `transientGatewayRetryInterceptor` import:

```ts
import { escapeJsonSlashesInterceptor } from './core/http/escape-json-slashes';
```

Then add it as the last entry of the interceptor list:

```ts
      withInterceptors([
        authInterceptor,
        readerOutputVersionInterceptor,
        transientGatewayRetryInterceptor,
        escapeJsonSlashesInterceptor,
      ]),
```

- [ ] **Step 7: Record the invariant** in `docs/architecture.md` §3. Append this row to the invariants table, after the `Origin` / `Referer` row:

```markdown
| Request bodies write `/` as **`\/`** — standard JSON escaping, which the backend's `json_decode` reads unchanged | `escapeJsonSlashesInterceptor` in `frontend/src/app/core/http/` | The production host's content filter drops any body holding a raw `https://bsky.app/…`-style URL and misses the escaped form (#1638). Swift's `JSONEncoder` escapes slashes by default — a native client must not opt into `.withoutEscapingSlashes`. |
```

- [ ] **Step 8: Gate and commit**

Run (from `frontend/`): `npm run check:static && ./node_modules/.bin/jest src/app/core`
Expected: all green.

```bash
git add frontend/src/app/core/http/escape-json-slashes.ts frontend/src/app/core/http/escape-json-slashes.spec.ts frontend/src/app/app.config.ts docs/architecture.md
git commit -m "fix(#1638): escape slashes in json request bodies past the host's content filter"
```

---

### Task 2: Name the host's block in the error message

**Files:**
- Modify: `frontend/src/app/core/problem.ts` (`unexpectedReplyTitle`)
- Modify: `frontend/src/app/core/problem.spec.ts` (the 2xx HTML test, about lines 106–123)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: no new exports. The `parseProblem()` / `parseProblemAsync()` titles change for a 2xx HTML body only.

- [ ] **Step 1: Update the spec first.** Replace the test `'names the web server page title when a 2xx body is an HTML page'` with:

```ts
  // Strato's firewall answers with its Apache 503 page under HTTP 200, so Angular
  // fails JSON.parse and hands the body over as { error, text } (#1112, #1638).
  it('says the web host blocked the request when a 2xx body is an HTML page', () => {
    const error = new HttpErrorResponse({
      status: 200,
      error: {
        error: new SyntaxError('Unexpected token <'),
        text: '<html><head>\n<title>503 Service Unavailable</title>\n</head><body></body></html>',
      },
    });

    expect(parseProblem(error)).toEqual({
      type: 'about:blank',
      title:
        'The web host blocked this request before it reached the app (it answered "503 Service Unavailable").',
      status: 200,
    });
  });
```

Leave the non-2xx HTML tests (502, and 504 as a Blob) as they are. They pin the unchanged "Try again in a minute" wording.

- [ ] **Step 2: Run it and watch it fail**

Run: `./node_modules/.bin/jest src/app/core/problem.spec.ts`
Expected: FAIL. The new test receives `The web server answered "503 Service Unavailable" instead of the app. Try again in a minute.`

- [ ] **Step 3: Implement.** In `frontend/src/app/core/problem.ts`, replace `unexpectedReplyTitle` with:

```ts
function unexpectedReplyTitle(error: HttpErrorResponse, text: string): string {
  const pageTitle = htmlPageTitle(text);
  if (pageTitle === null) {
    return `The server answered with an unexpected reply (HTTP ${error.status}).`;
  }
  if (isSuccessStatus(error.status)) {
    return `The web host blocked this request before it reached the app (it answered "${pageTitle}").`;
  }

  return `The web server answered "${pageTitle}" instead of the app. Try again in a minute.`;
}

/** The app never answers a 2xx with HTML, so such a page comes from a host-level filter. */
function isSuccessStatus(status: number): boolean {
  return status >= 200 && status < 300;
}
```

Do not use `error.ok`. An `HttpErrorResponse` always has `ok === false`.

- [ ] **Step 4: Run it and watch it pass**

Run: `./node_modules/.bin/jest src/app/core/problem.spec.ts`
Expected: PASS, including the unchanged 502 and 504 HTML tests.

- [ ] **Step 5: Break-test the boundary.** Temporarily change `status < 300` to `status < 200`. Confirm the new test FAILS, then restore the line by editing it back.

- [ ] **Step 6: Full frontend gate and commit**

Run (from `frontend/`): `npm run check:static && ./node_modules/.bin/jest`
Expected: all green.

```bash
git add frontend/src/app/core/problem.ts frontend/src/app/core/problem.spec.ts
git commit -m "fix(#1638): say the web host blocked a request answered with an html page under 2xx"
```

---

## After the tasks

- Push `fix/1638-social-url-firewall`, then open a PR into `develop`. Its body says `Refs #1638`, not `Closes`, because spec §3 (image proxy, OPML, backup) is still open pending the prod probe.
- Hand Lars the verification he must run after deploy: add `https://bsky.app/profile/<handle>/rss` as a feed on prod. It should succeed, and the prod log should show `Matched route` for the `POST /api/subscriptions`.
