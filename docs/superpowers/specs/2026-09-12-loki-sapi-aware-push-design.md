# SAPI-aware Loki log delivery (#1003)

## Problem

Every web request that emits an `info`-level log flushes the backend's Loki
buffer to Grafana Cloud inside `kernel.terminate`
(`LokiFlushListener::onKernelTerminate` → `LokiPushHandler::flush` →
`LokiClient::push`). On PHP-FPM this is invisible: `Response::send()` calls
`fastcgi_finish_request()`, the client already has the response, and the push
runs after the connection closes. Strato does not run FPM.

Measured on the Strato host (2026-09-12):

- Web SAPI is `cgi-fcgi`; `function_exists('fastcgi_finish_request')` is `false`.
- The Loki push URL is set in the database, so logs go to Grafana Cloud
  (`logs-prod-012.grafana.net`).
- Each push takes 0.5–0.6 s; ~396 push round-trips that day.

Because the SAPI cannot detach the client, `kernel.terminate` runs while the
client is still waiting. Every logging request therefore pays ~0.5 s that the
user sees. That is the observed "Strato is generally slower".

This is not the Pyroscope profiling work (#993): the Pyroscope URL is empty and
Strato has no `ext-excimer`, so profiling never runs there.

## Goal

Keep the direct push wherever it is free, and take it off the request path only
where it is not. Backend logs must still reach Grafana Cloud on Strato.

## Constraints

- Fail-open, always: logging must never break or slow a real user request. Every
  new path swallows `\Throwable`.
- No change to file logging (`prod-YYYY-MM-DD.log`) — it stays the primary
  on-host diagnostic.
- No change to PHP-FPM / dev / Docker behaviour.
- Clean Code house style (`final readonly`, interfaces injected, thin
  controllers, PHPMD-clean touched files).
- The maintenance tick is triggered by an external cronjob on `ganesh.local`
  that POSTs to `/maintenance/tick`; it is machine-facing, so a Loki push during
  the tick costs no user anything.

## Discriminator

The push blocks a user only when a web client waits on a response that cannot be
flushed early. That is true only for a web SAPI without
`fastcgi_finish_request()`.

- **Direct mode** — `fastcgi_finish_request()` exists (PHP-FPM), OR the SAPI is
  CLI (`messenger:consume`, console; no client waiting). Push over HTTP as today.
- **Spool mode** — otherwise (`cgi-fcgi` web request). Write to a local spool;
  never touch the network on the request path.

The SAPI is fixed for a process's lifetime, so the mode is decided once at
service construction.

## Components

All in `backend/src/Service/Logging/Loki/`.

### `LokiSink` (interface)

```php
interface LokiSink
{
    /** @param list<array{ts: string, line: string, labels: array<string, string>}> $lines */
    public function write(array $lines): void;
}
```

The single seam between "the handler has lines to deliver" and "how they leave
the process".

### `DirectLokiSink implements LokiSink`

Wraps the existing `LokiClient::push()`. This is today's behaviour, unchanged.

### `SpoolLokiSink implements LokiSink`

Appends the lines as one JSON record to a fresh, uniquely-named file in the spool
directory — maildir-style, one file per flush. No locking, no offset tracking,
no truncation races: concurrent `cgi-fcgi` processes each write their own file.
A write failure is swallowed.

- Spool directory: `%kernel.logs_dir%/loki-spool/` (created on demand, mode 0770).
- File name: `<unix-micros>-<random hex>.json`.
- File content: `json_encode($lines)` — the exact list shape `LokiClient::push`
  consumes, so shipping is a decode-and-forward.

### `LokiSinkFactory`

Returns `DirectLokiSink` when `function_exists('fastcgi_finish_request')` is true
or `PHP_SAPI === 'cli'`; otherwise `SpoolLokiSink`. Wired as the service factory
for the `LokiSink` alias.

### `LokiSpoolShipper`

Drains the spool. For each file in the directory (oldest first): read, decode,
`LokiClient::push($lines)`, then delete the file. Fail-open:

- A push that throws leaves the file in place for the next tick (at-least-once;
  Loki dedupes identical `(stream, ts, line)` triples, and a rare duplicate log
  line is acceptable).
- A file that cannot be decoded is deleted, not retried forever (a partial write
  from a crashed process must not wedge the queue).
- An empty or absent directory is a no-op.

Returns a small report (files shipped, files failed) for the tick response body.

### `LokiPushHandler` change

`flush()` calls `$this->sink->write($lines)` instead of
`$this->client->push($lines)`. The buffer, threshold, and formatter are
unchanged. The handler no longer depends on `LokiClient`; it depends on
`LokiSink`.

### `MaintenanceTick` change

`run()` gains a final step that calls `LokiSpoolShipper` and includes its report
in `MaintenanceTickReport`. The shipper runs after refresh/recommendations/
digests and is guarded by its own fail-open, independent of the aborted-EM guard
(it uses no EntityManager). The shipper's own push is a blocking HTTP call, but
the tick is the machine-facing cron request, so that is intended.

## Data flow

**Strato web request:** request → logs buffer in `LokiPushHandler` → terminate →
`SpoolLokiSink` writes `loki-spool/<micros>-<hex>.json` (no network) → response
returns. The user never waits on Loki.

**Strato tick (ganesh cron):** cron POSTs `/maintenance/tick` → refresh,
recommendations, digests → `LokiSpoolShipper` reads every spool file → pushes to
Grafana Cloud → deletes shipped files. The tick's own log lines spool and ship
on the following tick.

**FPM / dev / Docker web request:** unchanged — `DirectLokiSink` pushes at
terminate after the response is flushed.

**CLI worker (`messenger:consume`, where present):** `DirectLokiSink` pushes at
`WorkerMessageHandled` / `WorkerMessageFailed`; no client waits.

## Error handling

- `SpoolLokiSink::write` — swallows `\Throwable`; a failed spool write drops that
  batch, exactly as today's failed HTTP push would.
- `LokiSpoolShipper` — per-file try/catch as above; the overall drain never
  throws into the tick.
- `LokiClient` and the file handler are unchanged.

## Testing

- `DirectLokiSinkTest` — forwards lines to `LokiClient` verbatim.
- `SpoolLokiSinkTest` — writes one file per flush; content decodes to the input
  lines; a write error is swallowed.
- `LokiSpoolShipperTest` — round-trip: spool two batches, ship, assert
  `LokiClient` received both, files are gone; a push failure leaves the file;
  a corrupt file is deleted; empty directory is a no-op.
- `LokiSinkFactoryTest` — picks `DirectLokiSink` when the function exists or SAPI
  is CLI, `SpoolLokiSink` otherwise. (Inject the two facts, do not read globals
  directly, so both branches are testable.)
- `MaintenanceTickTest` — the tick invokes the shipper and folds its report in;
  an empty spool is a clean no-op.
- `LokiFlushListenerTest` and existing Loki tests — stay green against
  `DirectLokiSink` (the default in the test SAPI).
- Mutation gate (`composer infection:diff`) over the touched files.

## Out of scope

- Pyroscope (inert on Strato).
- The file log handler (unchanged).
- Any change to FPM / worker delivery timing.
- Spool retention beyond delete-after-ship (cron cadence bounds the backlog;
  files are removed once delivered).
