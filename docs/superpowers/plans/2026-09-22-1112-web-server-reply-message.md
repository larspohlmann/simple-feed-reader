# #1112 Web-Server Reply Message Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a response is not a `problem+json` document, the error banner names what the server actually answered (the web server's page title, or the HTTP status) instead of "Something went wrong".

**Architecture:** `parseProblem()` / `parseProblemAsync()` in `frontend/src/app/core/problem.ts` keep their signatures and the `Problem` shape. Only the fallback changes: it receives the response text and derives the title from it. An HTML body yields *The web server answered "<page title>" instead of the app. Try again in a minute.*; any other non-problem body yields *The server answered with an unexpected reply (HTTP <status>).* Status `0` ("Could not reach the server") and `413` (`REQUEST_TOO_LARGE`, #458) are unchanged. No host- or provider-specific matching.

**Tech Stack:** Angular 20 (`HttpErrorResponse`), TypeScript, Jest (jsdom) in the Docker `frontend` container.

**Spec:** GitHub issue #1112 (`gh issue view 1112`).

## Global Constraints

- Branch `fix/1112-web-server-reply-message` off `develop`. Work in place: no worktree, no `checkout`/`reset`/`stash` beyond creating the branch. Do NOT push, do NOT open a PR.
- Commit format `type(#1112): summary`, lower-case summary. NO attribution lines (no `Co-Authored-By`, no "Generated with").
- TDD: failing test first, watch it fail, then implement. Tests are production code.
- Clean Code (root `CLAUDE.md`): intent-revealing names, single-purpose functions, guard clauses, no boolean flag parameters. Comments: default to none; one line, three at the absolute most; delete comments that restate code.
- Prettier `printWidth` is 100. Keep every line ≤ 100 characters. Run `npx prettier --write <file>` from `frontend/` before each commit.
- Frontend unit tests run **inside the Docker container**, one run at a time (concurrent runs OOM it): from the repo root, `docker compose exec -T frontend npx jest src/app/core/problem.spec.ts`. The container is up (`docker compose ps`).
- Do not change `Problem`'s fields, the `type` values (`'about:blank'`, `REQUEST_TOO_LARGE`), or the `outcomeIsUnproven()` rule. Do not change `passkey.service.ts`.
- Copy stays hard-coded English, like the fallback strings today (#196 is a separate, unfixed issue).
- Do not use `HttpErrorResponse.statusText` in the copy: Angular replaces an empty one with `'Unknown Error'`, and browsers send an empty one over HTTP/2.

## Angular facts the tasks rely on

- A **2xx** body that fails `JSON.parse` arrives as an `HttpErrorResponse` whose `error` is `{ error: SyntaxError, text: '<the raw body>' }` (Angular `HttpXhrBackend`, "The parse error contains the text of the body that failed to parse").
- A **non-2xx** body that is not JSON arrives with `error` as the raw **string**.
- A request made with `responseType: 'blob'` arrives with `error` as a **Blob**; `parseProblemAsync()` reads it with `.text()`.
- This is exactly the production case in #1112: Strato's bot-protection page is HTTP **200** with an HTML body whose `<title>` is `503 Service Unavailable`.

---

### Task 1: Name the web server's page title in the fallback (sync and Blob paths)

**Files:**
- Modify: `frontend/src/app/core/problem.ts:43-92`
- Test: `frontend/src/app/core/problem.spec.ts`

**Interfaces:**
- Consumes: `parseProblem(err: HttpErrorResponse): Problem`, `parseProblemAsync(err: HttpErrorResponse): Promise<Problem>` (public, unchanged).
- Produces (private, Task 2 builds on these exact names):
  - `problemFromBody(body: unknown, status: number): Problem | null` — `null` when the body is not a problem document.
  - `responseText(body: unknown): string` — the raw body text for a string body or Angular's `{ error, text }` parse-failure shape; `''` otherwise.
  - `htmlPageTitle(text: string): string | null` — the trimmed, whitespace-collapsed `<title>` of an HTML body, `null` when there is none.
  - `fallbackProblem(err: HttpErrorResponse, text: string): Problem`.
  - `unexpectedReplyTitle(err: HttpErrorResponse, text: string): string`.

- [ ] **Step 1: Write the failing tests**

Append inside the `describe('parseProblem', …)` block of `frontend/src/app/core/problem.spec.ts`, before its closing `});`:

```ts
  // Strato's bot protection answers with its Apache 503 page under HTTP 200, so
  // Angular fails JSON.parse and hands the body over as { error, text } (#1112).
  it('names the web server page title when a 2xx body is an HTML page', () => {
    const err = new HttpErrorResponse({
      status: 200,
      error: {
        error: new SyntaxError('Unexpected token <'),
        text: '<html><head>\n<title>503 Service Unavailable</title>\n</head><body></body></html>',
      },
    });

    expect(parseProblem(err)).toEqual({
      type: 'about:blank',
      title: 'The web server answered "503 Service Unavailable" instead of the app. Try again in a minute.',
      status: 200,
    });
  });

  it('names the web server page title when a non-2xx body is an HTML page', () => {
    const err = new HttpErrorResponse({
      status: 502,
      error: '<html><head><title>  502   Bad\nGateway </title></head></html>',
    });

    expect(parseProblem(err).title).toBe(
      'The web server answered "502 Bad Gateway" instead of the app. Try again in a minute.',
    );
  });

  it('names the web server page title when a Blob body is an HTML page', async () => {
    const body = new Blob([], { type: 'text/html' });
    body.text = jest.fn().mockResolvedValue('<html><head><title>504 Gateway Time-out</title></head></html>');
    const err = new HttpErrorResponse({ status: 504, error: body });

    await expect(parseProblemAsync(err)).resolves.toEqual({
      type: 'about:blank',
      title: 'The web server answered "504 Gateway Time-out" instead of the app. Try again in a minute.',
      status: 504,
    });
  });
```

Then run `npx prettier --write src/app/core/problem.spec.ts` from `frontend/` (Prettier will wrap the long `text:` line and the long `mockResolvedValue` line).

- [ ] **Step 2: Run the spec to verify the three new tests fail**

Run from the repo root:

```bash
docker compose exec -T frontend npx jest src/app/core/problem.spec.ts
```

Expected: 3 failed, 8 passed. Each failure shows `title: "Something went wrong"` where the page-title copy is expected.

- [ ] **Step 3: Implement the fallback that reads the page title**

Replace everything in `frontend/src/app/core/problem.ts` from the `parseProblem` docblock (line 43) to the end of the file with:

```ts
/** Map any HttpErrorResponse to the backend's problem+json contract, with a
 *  fallback that names what the response contained when the body is missing
 *  or not problem+json (network errors, gateways, web-server pages). */
export function parseProblem(err: HttpErrorResponse): Problem {
  return problemFromBody(err.error, err.status) ?? fallbackProblem(err, responseText(err.error));
}

/** Read an error body that Angular delivered as a Blob before mapping it. */
export async function parseProblemAsync(err: HttpErrorResponse): Promise<Problem> {
  if (!(err.error instanceof Blob)) return parseProblem(err);

  const text = await err.error.text().catch(() => '');
  return problemFromBody(parseJsonOrNull(text), err.status) ?? fallbackProblem(err, text);
}

function problemFromBody(body: unknown, status: number): Problem | null {
  if (!body || body instanceof Blob || typeof body !== 'object' || !('type' in body)) return null;

  const b = body as Record<string, unknown>;
  return {
    type: String(b['type'] ?? 'about:blank'),
    title: String(b['title'] ?? 'Request failed'),
    status: typeof b['status'] === 'number' ? (b['status'] as number) : status,
    detail: typeof b['detail'] === 'string' ? (b['detail'] as string) : undefined,
    errors: (b['errors'] as Record<string, string[]> | undefined) ?? undefined,
    accountStatus:
      typeof b['accountStatus'] === 'string' ? (b['accountStatus'] as string) : undefined,
    invalidatedPasskeyCount:
      typeof b['invalidatedPasskeyCount'] === 'number'
        ? (b['invalidatedPasskeyCount'] as number)
        : undefined,
  };
}

function parseJsonOrNull(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

/** A 2xx body that failed JSON.parse arrives as Angular's `{ error, text }`;
 *  a non-2xx body it could not parse stays a plain string. */
function responseText(body: unknown): string {
  if (typeof body === 'string') return body;
  if (body && typeof body === 'object' && 'text' in body && typeof body.text === 'string') {
    return body.text;
  }
  return '';
}

function fallbackProblem(err: HttpErrorResponse, text: string): Problem {
  if (err.status === 413) {
    return {
      type: REQUEST_TOO_LARGE,
      title: 'The file is too large for this server to accept',
      status: err.status,
    };
  }
  if (err.status === 0) {
    return { type: 'about:blank', title: 'Could not reach the server', status: err.status };
  }
  return { type: 'about:blank', title: unexpectedReplyTitle(err, text), status: err.status };
}

function unexpectedReplyTitle(err: HttpErrorResponse, text: string): string {
  const pageTitle = htmlPageTitle(text);
  if (pageTitle === null) return 'Something went wrong';

  return `The web server answered "${pageTitle}" instead of the app. Try again in a minute.`;
}

function htmlPageTitle(text: string): string | null {
  const title = /<title[^>]*>([^<]*)<\/title>/i.exec(text)?.[1]?.replace(/\s+/g, ' ').trim();
  return title ? title : null;
}
```

`unexpectedReplyTitle()` keeps `'Something went wrong'` for the non-HTML case in this task; Task 2 replaces that line. The `err` parameter is unused until Task 2 — if ESLint flags it, name it `_err` for this task only and rename back in Task 2.

Run `npx prettier --write src/app/core/problem.ts` from `frontend/`.

- [ ] **Step 4: Run the spec to verify all tests pass**

```bash
docker compose exec -T frontend npx jest src/app/core/problem.spec.ts
```

Expected: 11 passed, 0 failed.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/core/problem.ts frontend/src/app/core/problem.spec.ts
git commit -m "fix(#1112): name the web server's page title when the reply is not problem+json"
```

---

### Task 2: Carry the HTTP status when the unexpected reply is not an HTML page

**Files:**
- Modify: `frontend/src/app/core/problem.ts` (`unexpectedReplyTitle` only)
- Test: `frontend/src/app/core/problem.spec.ts`

**Interfaces:**
- Consumes from Task 1: `unexpectedReplyTitle(err: HttpErrorResponse, text: string): string`, `htmlPageTitle(text: string): string | null`.
- Produces: nothing new; the non-HTML fallback title becomes `The server answered with an unexpected reply (HTTP <status>).`

- [ ] **Step 1: Write the failing tests and update the one existing expectation**

In `frontend/src/app/core/problem.spec.ts`, change the existing test `'uses the safe fallback when a Blob does not contain JSON'` so its expectation reads:

```ts
    await expect(parseProblemAsync(err)).resolves.toEqual({
      type: 'about:blank',
      title: 'The server answered with an unexpected reply (HTTP 500).',
      status: 500,
    });
```

Then append inside the `describe` block, after the tests added in Task 1:

```ts
  it('carries the HTTP status when a non-HTML body is not a problem document', () => {
    const err = new HttpErrorResponse({ status: 502, error: 'upstream connect error' });

    expect(parseProblem(err)).toEqual({
      type: 'about:blank',
      title: 'The server answered with an unexpected reply (HTTP 502).',
      status: 502,
    });
  });

  it('carries the HTTP status when an HTML body has no title', () => {
    const err = new HttpErrorResponse({ status: 200, error: { text: '<html><body>x</body></html>' } });

    expect(parseProblem(err).title).toBe('The server answered with an unexpected reply (HTTP 200).');
  });

  it('keeps "Could not reach the server" for a dropped connection', () => {
    const err = new HttpErrorResponse({ status: 0, error: '<html><title>x</title></html>' });

    expect(parseProblem(err).title).toBe('Could not reach the server');
  });
```

Run `npx prettier --write src/app/core/problem.spec.ts` from `frontend/`.

- [ ] **Step 2: Run the spec to verify the changed and new tests fail**

```bash
docker compose exec -T frontend npx jest src/app/core/problem.spec.ts
```

Expected: 3 failed (`Blob does not contain JSON`, `non-HTML body`, `HTML body has no title`), 11 passed. The dropped-connection test passes already; it pins the branch so a later edit cannot move status 0 into the status-label copy.

- [ ] **Step 3: Replace the generic line in `unexpectedReplyTitle`**

In `frontend/src/app/core/problem.ts`, change `unexpectedReplyTitle` to:

```ts
function unexpectedReplyTitle(err: HttpErrorResponse, text: string): string {
  const pageTitle = htmlPageTitle(text);
  if (pageTitle === null) {
    return `The server answered with an unexpected reply (HTTP ${err.status}).`;
  }

  return `The web server answered "${pageTitle}" instead of the app. Try again in a minute.`;
}
```

If Task 1 renamed the parameter to `_err`, rename it back to `err`. Run `npx prettier --write src/app/core/problem.ts` from `frontend/`.

- [ ] **Step 4: Run the spec to verify all tests pass**

```bash
docker compose exec -T frontend npx jest src/app/core/problem.spec.ts
```

Expected: 14 passed, 0 failed.

- [ ] **Step 5: Confirm "Something went wrong" is gone from the source file**

```bash
grep -n "Something went wrong" frontend/src/app/core/problem.ts
```

Expected: only the `REQUEST_TOO_LARGE` docblock line (line ~37) matches, which describes history. If that docblock now reads wrong, shorten it to: `/** An oversized request body, refused by the web server before the app ran; features that upload a file match on this to offer their own wording (#458). */` (one to three lines).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/core/problem.ts frontend/src/app/core/problem.spec.ts
git commit -m "fix(#1112): carry the http status when an unexpected reply is not an html page"
```

---

### Task 3: Gate, build, and the full unit suite

**Files:**
- None modified unless the gate reports something.

- [ ] **Step 1: Run the CI gate inside the container**

From the repo root:

```bash
docker compose exec -T frontend npm run check
```

Expected: ESLint, Prettier, Stylelint, `typecheck:spec`, and Jest all pass. If Prettier fails, run `npx prettier --write src/app/core/problem.ts src/app/core/problem.spec.ts` from `frontend/`, re-run, and amend the last commit with `git commit --amend --no-edit`.

- [ ] **Step 2: Run the production build**

`npm run check` does not type-check templates; the build does.

```bash
docker compose exec -T frontend npm run build
```

Expected: build succeeds with no errors.

- [ ] **Step 3: Check other specs that pin the old copy**

```bash
grep -rn "Something went wrong" frontend/src/app --include='*.spec.ts'
```

Expected: matches only in specs that construct a `Problem` literal with that title themselves (`passkey-offer-dialog.component.spec.ts`, `account-section.component.spec.ts`, `error-banner.component.spec.ts`, `ai-section.component.spec.ts` via i18n, `backup-section.component.spec.ts`). None of them call `parseProblem` on a non-JSON body, so they stay green in Step 1. If one failed in Step 1, it asserted the fallback copy through `parseProblem`; update its expectation to the new copy for that status and commit as `test(#1112): expect the unexpected-reply copy`.

- [ ] **Step 4: Report**

State the gate and build results with their output. Do not push and do not open a PR; Lars decides that.
