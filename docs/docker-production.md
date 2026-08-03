# Running in production (Docker)

The production stack is three containers — MySQL, the production PHP image,
and nginx serving the compiled app with `/api` handled same-origin — defined
in [`docker-compose.prod.yml`](../docker-compose.prod.yml). It is completely
separate from the [development stack](local-docker.md): its own compose file,
its own project name (`simple-feed-reader-prod`), its own volumes. Both can
run on the same machine.

**Why the separation is strict:** the dev stack injects
`MAILER_DSN=smtp://mailpit:1025` — every mail lands in a local inbox
(Mailpit) and never reaches a real mailbox. That is perfect for development
and catastrophic in production, where registration, admin approval, and
password reset all depend on real delivery (issue #65). The production stack
therefore *requires* a real mail transport before it starts, and Mailpit is
unreachable from it by construction.

---

## 1. Install

One command, on a machine with [Docker](https://docs.docker.com/get-docker/)
and [git](https://git-scm.com/downloads):

```bash
curl -fsSL https://raw.githubusercontent.com/larspohlmann/simple-feed-reader/main/scripts/install.sh | bash
```

The installer clones the project, checks out the latest release, generates
every secret it can (database passwords, signing keys), and asks for the few
values only you know:

- **How users reach the instance** — three questions, because this is three
  decisions:
  1. Plain HTTP, direct (the default); HTTPS with a certificate this stack
     serves; or HTTPS behind a reverse proxy that terminates TLS. Choose the
     third on a machine that already publishes port 80 — see §2.
  2. The **hostname**, `localhost` by default. Paste a whole URL and the
     installer reduces it to the host.
  3. The **port**, which defaults to the one the first answer implies: 80,
     443, or 8080 behind a proxy. The installer checks whether it is free and
     asks again when it is not.

  Together these become `PUBLIC_URL` and the published container ports. For
  OAuth sign-in, and for Safari, use a real HTTPS origin.
- **How to send mail** — an SMTP relay (your mail provider's host, port,
  username, password), or the machine's own MTA if it runs one. There is no
  default: a feed reader that cannot send mail cannot register users or
  reset passwords, so this is asked up front. You can also answer "later";
  the installer then stops and tells you how to finish by hand.

At the end it offers to send a **test mail** — accept, and a wrong relay
password surfaces immediately instead of at the first lost registration.

Prefer doing it manually? Clone the repository, check out the latest
`vX.Y.Z` tag, copy `.env.prod.example` to `.env.prod`, fill it in (the
comments explain every value), and run `./scripts/prod-start.sh`.

## 2. TLS

The stack serves TLS itself when you give it a certificate, and plain HTTP
when you do not. The installer's first question sets this up for you; what
follows is what it writes, and how to do it by hand.

- **Bring a certificate** (recommended when nothing else terminates TLS):
  put `fullchain.pem` and `privkey.pem` — the Let's Encrypt names — into
  `docker/certs-prod/`, then run `./scripts/prod-start.sh` again. Port 443
  serves the app; port 80 redirects to it. The installer offers to generate
  these two files with `mkcert` when `mkcert` is installed. Take that offer
  only for a private instance: a mkcert certificate is trusted solely on
  machines holding your mkcert root CA, every other visitor gets a browser
  warning, and OAuth providers refuse it. After a certificate renewal,
  re-run `./scripts/prod-start.sh` (or `docker compose -p
  simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod
  restart web`) to load the new files.
- **Or put a reverse proxy in front** (Caddy, Traefik, nginx) — the answer to
  pick when this machine already publishes port 80, because one host address
  publishes a port once. Leave `docker/certs-prod/` empty; the stack serves
  plain HTTP. The installer then writes `WEB_HTTP_PORT=8080`,
  `WEB_BIND_ADDRESS=127.0.0.1` so only the proxy on this machine can reach
  it, and moves `WEB_TLS_PORT` to 8443 — the stack publishes that port even
  in this mode (both ports are always published — see the comment in
  `.env.prod.example`), so leaving it at 443 collides with the proxy.
  `PUBLIC_URL` stays the origin users type, without the loopback port. Set
  `WEB_BIND_ADDRESS=0.0.0.0` if the proxy runs on another machine. By hand,
  write those four values into `.env.prod` yourself. Example Caddyfile:

  ```
  reader.example.org {
      reverse_proxy 127.0.0.1:8080
  }
  ```

Either way, set `PUBLIC_URL` in `.env.prod` to the HTTPS origin users
actually use — mail links and OAuth redirects are built from it.

`WEB_MODE=tls|http` in `.env.prod` overrides the automatic certificate
detection above; `auto` (the default) is almost always right.

## 3. First admin

A fresh instance has no administrator. The easiest path is the browser:
`prod-start.sh` generates an `ADMIN_SETUP_SECRET` automatically and prints it
in its end-of-run summary for as long as no administrator exists. Open the
public URL — the one-time setup screen appears instead of login — and enter
your email, a password, and that secret. Afterwards, remove
`ADMIN_SETUP_SECRET` from `.env.prod` (the endpoint self-disables once an
admin exists, but a dead secret has no business staying on disk).

The secret is not shown anymore? Read it from the file:

```bash
grep '^ADMIN_SETUP_SECRET=' .env.prod
```

Prefer the shell instead:

```bash
docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod \
  exec -u www-data php bin/console app:admin:create you@example.com
```

Why the path is gated either way is explained in
[first-run-setup.md](first-run-setup.md).

## 4. Verify mail delivery

Repeatable at any time, through the real configured transport:

```bash
docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod \
  exec -u www-data php bin/console mailer:test you@example.com
```

The mail must arrive in that inbox (check spam on the first try). Common
`MAILER_DSN` shapes — set in `.env.prod`, URL-encode the username and
password if you write one by hand:

| Setup | DSN |
|---|---|
| Provider SMTP, STARTTLS | `smtp://user%40example.org:PASSWORD@smtp.example.org:587` |
| Provider SMTP, implicit TLS | `smtps://user%40example.org:PASSWORD@smtp.example.org:465` |
| MTA on the Docker host | `smtp://host.docker.internal:25` |

The host-MTA option delivers only as well as that MTA is set up (SPF, DKIM,
reverse DNS). If mail lands in spam, fix the MTA's reputation or switch to a
relay.

**Mailpit is a development tool.** It exists only in the dev stack's compose
file; the production stack cannot reach it, and no production configuration
should ever point at it.

## 5. Running without mail

A private or single-operator instance may not want a mail transport at all.
Set `MAIL_DISABLED=1` together with `MAILER_DSN=null://null` in `.env.prod`
to opt in — both are required. The installer's mail question offers this as
choice **"4) No mail"** (at install, or later via
`./scripts/prod-configure.sh` — see §7) and sets both automatically, along
with a placeholder `MAIL_FROM` derived from `PUBLIC_URL` if none is set yet
(it is never actually sent).

A merely *forgotten* mailer still fails loud, on purpose:
`docker-compose.prod.yml` keeps `MAILER_DSN` a required variable (empty
refuses to start), and the runtime guard answers every request with 500 if
it sees the null transport (`null://null`) without `MAIL_DISABLED=1` set
alongside it. Mailless is a deliberate opt-in, never something an instance
falls into by leaving a field blank.

Consequences, once mailless is on:

- **Email confirmation is forced off.** New users skip the verification
  step entirely, so an account can go active with an address that was never
  proven to receive mail (a typo, or someone else's inbox — weigh that
  against the convenience before enabling it on a public instance).
- **Password reset by email is unavailable.** Recover an account from the
  shell instead:

  ```bash
  docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod \
    exec -u www-data php bin/console app:user:reset-password you@example.com --generate
  ```

  `--generate` mints a random password and prints it once, for you to relay
  to the user out of band; omit the flag to type one at a hidden prompt
  instead. The equivalent also exists as an admin API call —
  `POST /api/admin/users/{id}/reset-password` (`ROLE_ADMIN`) — which returns
  a freshly generated password once, in the response body.

The two admin toggles that decide whether email confirmation and admin
approval are required in the first place — both on by default — are
covered in [first-run-setup.md](first-run-setup.md); mailless mode forces
the email-confirmation one off regardless of how it is set.

## 6. Update

```bash
cd simple-feed-reader && ./scripts/update.sh
```

This checks out the newest release and re-runs the production bring-up
(rebuild, migrate, health check). Data is kept. `prod-start.sh` is
idempotent — running it again is always safe.

## 7. Reconfigure

To change the public origin or the mail settings later, re-run the
installer's questions against the existing install. Every question offers the
current value, so pressing return through all of them changes nothing;
answering the port question differently re-publishes the stack on the new
port:

```bash
cd simple-feed-reader && ./scripts/prod-configure.sh
```

Each question defaults to the current value; the script applies the answers
(the same idempotent bring-up as an update) and offers the mail check from
§4. Secrets and passwords are deliberately not touched: regenerating
`JWT_PASSPHRASE` would lock the existing signing key, and the MySQL
passwords already initialized the database volume. Ports and optional
values are a hand edit in `.env.prod` (the comments in `.env.prod.example`
explain each one), applied with `./scripts/prod-start.sh`.

## 8. Backup

Everything worth keeping lives in three named volumes: the database
(`mysql-data`), logs and cache pools (`php-var`), and the JWT signing keys
(`jwt-keys`). A database dump before major updates:

```bash
docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod \
  exec mysql sh -c 'exec mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' > backup.sql
```

Losing `jwt-keys` is not fatal — new keys are generated on the next start —
but it signs every user out.

## 9. Troubleshooting

- **Compose refuses to start and names a variable** — that value is empty in
  `.env.prod`. The comments in `.env.prod.example` explain each one.
- **Every request answers 500** — the runtime guard refuses to serve while a
  committed placeholder is in use (`ALTCHA_HMAC_KEY`, `MAILER_DSN`). The log
  names the variable:
  `docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod exec php sh -c 'tail -n 50 var/log/prod-*.log'`
- **Mail says sent but never arrives** — run the `mailer:test` check above;
  then check the spam folder, then the transport's own logs. With the
  host-MTA DSN, check the host's mail queue (`mailq`).
- **Port 80/443 already taken** — set `WEB_HTTP_PORT` / `WEB_TLS_PORT` in
  `.env.prod` and re-run `./scripts/prod-start.sh`.
