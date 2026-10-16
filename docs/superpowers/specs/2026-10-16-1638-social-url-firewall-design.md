# #1638 — Strato's content filter blocks social-network URLs in request bodies

## Problem

Strato's web-host firewall drops any request whose body contains a social-network URL
(`https://bsky.app/…`, `https://bsky.social/…`, `https://mastodon.social/…`). PHP never
runs. The firewall answers with its Apache "503 Service Unavailable" page, sent with
**HTTP 200** and `Content-Type: application/x-httpd-php`. A query string that carries such
a URL gets a real 503.

The filter misses JSON-escaped slashes: `https:\/\/bsky.app\/…` reaches the app (probed
2026-10-14, #1636).

## Decision

Approach A from the issue, agreed 2026-10-16: **every JSON request body the SPA sends
writes `/` as `\/`**. This is standard JSON, the same thing PHP's `json_encode` and Swift's
`JSONEncoder` write by default, so:

- the backend needs no change. Every existing functional test already posts
  `json_encode` output, so the backend is proven to read escaped slashes;
- the wire contract is unchanged, and a native iOS client using `JSONEncoder` defaults is
  already past the filter;
- it covers all 76 `post`/`put`/`patch` calls, not only the subscribe dialog.

Known risk: the fix relies on the filter's blind spot. If Strato tightens the rule, it
breaks silently. Fallback: explicit URL-field encoding (option 3 in the issue). Asking
Strato to disable the rule for `/reader/api` (option 1) is Lars's to do alongside this.

Rejected: base64 URL fields per endpoint (API churn, the iOS client must follow, and it
is not needed while A works).

## Scope

### 1. `escapeJsonSlashesInterceptor` (`frontend/src/app/core/http/`)

A functional `HttpInterceptorFn`, registered in `app.config.ts`.

- It applies only to a request whose body Angular itself would send as JSON:
  `request.detectContentTypeHeader() === 'application/json'` (objects, arrays, numbers,
  booleans). Strings (the OPML XML), `Blob`s (backup restore), `FormData`, `ArrayBuffer`,
  `URLSearchParams` and a null body pass through untouched.
- It replaces the body with `JSON.stringify(body).replaceAll('/', '\\/')`. A `/` appears in
  JSON text only inside string literals, so the blanket replace is exact. A literal
  backslash before a slash stays correct: `\\` + `/` becomes `\\\/`.
- A string body would make Angular infer `text/plain`, so the interceptor sets
  `Content-Type: application/json` unless the request already names one.
- It applies to every host, not only the API. The output is valid JSON anywhere.

### 2. A clearer message for the firewall page

`parseProblem()` / `parseProblemAsync()` currently say *The web server answered "<title>"
instead of the app. Try again in a minute.* for every HTML page. For the filter, a retry
never helps.

- An HTML page arriving under a **2xx** status (only a host-level interceptor produces
  that, since the app always answers JSON) gets: *The web host blocked this request before
  it reached the app (it answered "<title>").*
- An HTML page under a non-2xx status (a real 502/503/504 from a busy gateway) keeps the
  current "Try again in a minute" wording.

### 3. Deferred: non-JSON carriers (needs a prod probe first)

These carriers may be affected, but whether the filter decodes their escapes is unknown.
The prod probe was not run in this session, because the permission check blocked requests
to the live host.

| Carrier | Candidate fix | Probe needed |
|---|---|---|
| `GET /api/image-proxy?url=…` | full percent-encoding (Angular's codec leaves `:` and `/` raw) | does `%3A%2F%2Fbsky.app` pass? |
| `POST /api/opml/import` (raw XML) | `&#47;` in attribute values | does the filter read XML bodies / decode entities? |
| backup restore (raw JSON Blob) | re-serialise with escaped slashes | does it pass once the JSON is escaped? |

Once the probe has run, these become their own change, or a follow-up commit on this
branch.

## Testing

- Interceptor spec (`HttpTestingController`): an object body arrives as a string with
  `\/` and `Content-Type: application/json`, and `JSON.parse` of it equals the original;
  a string body, a `Blob`, `FormData` and a GET pass through unchanged; an explicit
  Content-Type is kept; a backslash-slash sequence round-trips.
- `problem.spec.ts`: the 2xx-HTML case gets the new wording; the non-2xx HTML cases keep
  the old one.
- No backend test. Every existing functional test already posts escaped slashes.
- Verification on prod is after deploy, by adding a `bsky.app` feed by URL. A worktree
  cannot drive the Docker stack while another session holds it.

## Docs

`docs/architecture.md` §3 (native-ready invariants) gains one line: request bodies
must keep `/` escaped as `\/` because of the host's content filter (#1638). An iOS client
must not opt into `.withoutEscapingSlashes`.
