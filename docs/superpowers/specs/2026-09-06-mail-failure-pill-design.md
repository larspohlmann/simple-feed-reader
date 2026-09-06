# Automated-email failure pill + recent-error log (#882)

## Problem

When an automated email fails — a digest send, or a deferred verification/reset
mail — the failure is only written to the Monolog app log. An admin has no
in-app signal that mail is silently failing. On Strato this bit hard: proxied
digest sends failed every five minutes for hours and nothing in the UI showed
it.

The project already solves this shape for feeds, on the **Organise** page: a red
count on a health card that expands to a list of recent errors, each with the
raw message. This work brings the same treatment to automated email, and to the
manual **Send test** action in admin Mail.

## Constraint that shapes the model

The pill and the error log are shown **only when the last email send failed**
(automated or manual test). A successful send of any kind clears the stored
failures. This is the feed-health mental model: a channel that recovers drops
off the display.

Chosen recovery model: **clear on success, global.** Any successful send —
digest, deferred account mail, or manual test — deletes every stored failure.
The table therefore only ever holds failures since the last success, so
"table non-empty" is the same statement as "the last send failed". The pill and
the log are shown when the count is greater than zero. No last-success
watermark is needed.

Accepted trade-off: in one digest sweep that sends to many recipients, one
recipient succeeding clears another recipient's failure that the same sweep
just recorded. This is acceptable, because the pill answers "is outgoing mail
working right now?", and the real failure mode (a down proxy or SMTP transport)
fails every recipient together.

## Backend

### Entity and table

New entity `App\Entity\MailSendFailure`, table `mail_send_failure`:

| Column | Type | Note |
|---|---|---|
| `id` | int, identity | |
| `kind` | string(16) | one of the `MailKind` values below |
| `recipient` | string(255) | the intended recipient address |
| `errorDetail` | TEXT | the message, already run through `ProxyHandshakeFailure::explain()` for proxied SMTP (#880) — the caught exception carries it |
| `createdAt` | datetime immutable | when the send failed |

New `enum App\Entity\MailKind: string`:

- `Digest` — a scheduled digest send.
- `Account` — a deferred verification or password-reset mail. The deferred
  flush site holds only a generic `Email` + `Envelope`, so verification and
  reset are not told apart cheaply; `Account` plus the stored recipient is the
  honest, sufficient label. This is the one deviation from the issue's
  "verification / reset" wording.
- `Test` — a manual "Send test" from admin Mail.

### Repository

`App\Repository\MailSendFailureRepository`:

- `add(MailSendFailure $failure): void` — persist and flush, then prune to the
  newest `RETENTION` rows. `RETENTION = 50`. Pruning is select-ids-then-delete,
  the portable two-step used by `RecommendationRunLogRepository`, so it runs on
  both suite dialects.
- `deleteAll(): void` — clear the whole table.
- `recent(int $limit): list<MailSendFailure>` — newest first, capped at
  `RETENTION`.
- `count(): int` — total rows.

### Service

`App\Service\Mail\MailDeliveryHealth` (`final readonly`):

- `recordFailure(MailKind $kind, string $recipient, string $error): void` —
  builds and `add()`s a `MailSendFailure`.
- `recordSuccess(): void` — `deleteAll()`. Idempotent; safe to call once per
  successful message in a loop.
- `view(): array{count: int, failures: list<...>}` — read side for the
  endpoint. A read; it does not mutate.

Response shaping for `view()` lives in `App\Http\MailDeliveryHealthJson` (a
static mapper), so the service returns domain rows and the mapper builds the
wire envelope. Each wire failure is `{kind, recipient, error, at}` with `at` in
`DATE_ATOM`.

### Wiring

| Path | success → `recordSuccess()` | failure → `recordFailure(...)` |
|---|---|---|
| `Service/Mail/Digest/SendDueDigests::sendAndAdvance()` | after `mailer->send()` returns | in the `TransportExceptionInterface` catch, kind `Digest`, recipient the user email. The existing `logger->error` stays. |
| `EventListener/DeferredMailFlushListener::flush()` | after `sendNow()` returns | in the `\Throwable` catch, kind `Account`, recipient from the envelope. The existing `logger->error` stays. |
| `Service/Mail/Settings/MailConnectionTester::test()` | on the `MailTestResult::ok()` return | on each `MailTestResult::failed(...)` return, kind `Test`, recipient the acting admin email. |

`SendDueDigests` gains one constructor dependency (`MailDeliveryHealth`),
taking it to nine — under the PHPMD `ExcessiveParameterList` limit.

### Endpoint

`GET /api/admin/mail/errors` on `Controller/Admin/AdminMailController`, under the
existing `^/api/admin/` ROLE_ADMIN prefix rule. The action is thin:

```php
return new JsonResponse($this->health->view());
```

JSON in, `application/problem+json` out, bearer-auth only — it passes the
native-iOS design-time checklist (docs/architecture.md §6): no browser-only
input, no cookie, no `text/html` fallback.

### Migration

One migration creating `mail_send_failure`. Migrations are not exercised by the
test suite (the schema is built from ORM metadata), so correctness is proven by
the dedicated CI leg that migrates from empty on both SQLite and MySQL and then
runs `doctrine:schema:validate`.

## Frontend

Area: `frontend/src/app/settings/admin/mail/`.

`MailSettingsService` gains:

- `failures = signal<MailFailure[]>([])` and
  `failureCount = computed(() => this.failures().length)`.
- `loadFailures()` — `GET .../mail/errors`, sets both. Called on section load,
  and again after a test resolves (a test flips the stored state).
- On a successful test, the service may clear `failures` optimistically; a plain
  `loadFailures()` after the probe settles is enough and is the single source of
  truth.

`mail-section.component`: a `mail-health` disclosure card rendered above the
settings group **only when `failureCount() > 0`**, mirroring
`organise-section.component`:

- Summary: a broken-heart icon, a heading, and a red count pill.
- Body: one row per failure — kind, recipient, time, and the message inside
  `<app-warning-box><code>…</code></app-warning-box>`.

Reuses `shared/disclosure`, `shared/warning-box`, `shared/icon`. No new overlay
is required; the message shows inline like `unhealthy-feed-row`. Styles go in
the sibling `.scss` with tokens only — no hex or raw `px` outside `theme/`.
Copy is added to `public/i18n/en.json` and `de.json`.

## Testing

Per the acceptance criteria:

- **Repository** — persistence, `recent` ordering and cap, pruning past
  `RETENTION`, and `deleteAll`.
- **Digest write** — functional: drive `SendDueDigests::run()` with a mailer
  that throws `TransportExceptionInterface`, assert one `Digest` row persisted;
  and with a mailer that succeeds, assert `recordSuccess` cleared the table.
- **Deferred write** — functional through a real `kernel.terminate` dispatch,
  not by calling `flush()` directly (direct-invocation tests mislead): a queued
  message whose transport throws leaves one `Account` row; one that sends clears
  the table.
- **Test action** — `MailConnectionTester::test()` records a `Test` row on
  failure and clears on success.
- **Endpoint** — admin-authenticated `GET /api/admin/mail/errors` returns the
  count and the shaped list; non-admin is refused by the prefix rule.
- **Frontend** — Jest: the card is absent at count 0, present with the count and
  the rows at count > 0.

Mutation testing gates the changed files (`composer infection:diff`); coverage
above targets the new service, repository, and wiring.

## Out of scope

- Distinguishing verification from reset mail (folded into `Account`).
- Live polling of the failure log (feed health does not poll either).
- Any change to how mail is sent or to the transport layer.
