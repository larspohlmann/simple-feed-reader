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

- **The public URL** — how users reach the instance. `http://localhost` (the
  default) is fine for a local or LAN instance. For OAuth sign-in, and for
  Safari, use a real HTTPS origin. The port in the URL becomes the published
  port.
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
when you do not:

- **Bring a certificate** (recommended when nothing else terminates TLS):
  put `fullchain.pem` and `privkey.pem` — the Let's Encrypt names — into
  `docker/certs-prod/`, then run `./scripts/prod-start.sh` again. Port 443
  serves the app; port 80 redirects to it. After a certificate renewal,
  re-run `./scripts/prod-start.sh` (or `docker compose -p
  simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod
  restart web`) to load the new files.
- **Or put a reverse proxy in front** (Caddy, Traefik, nginx): leave
  `docker/certs-prod/` empty; the stack serves plain HTTP on port 80. In
  `.env.prod`, move the port off 80 (`WEB_HTTP_PORT=8080`) and bind it to
  loopback (`WEB_BIND_ADDRESS=127.0.0.1`) so only the proxy on this machine
  can reach it. Example Caddyfile:

  ```
  reader.example.org {
      reverse_proxy 127.0.0.1:8080
  }
  ```

Either way, set `PUBLIC_URL` in `.env.prod` to the HTTPS origin users
actually use — mail links and OAuth redirects are built from it.

## 3. First admin

A fresh instance has no administrator. Create the first one over the shell:

```bash
docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod \
  exec -u www-data php bin/console app:admin:create you@example.com
```

The alternatives (and why the path is gated) are in
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

## 5. Update

```bash
cd simple-feed-reader && ./scripts/update.sh
```

This checks out the newest release and re-runs the production bring-up
(rebuild, migrate, health check). Data is kept. `prod-start.sh` is
idempotent — running it again is always safe.

## 6. Reconfigure

To change the public URL or the mail settings later, re-run the installer's
questions against the existing install; changing the URL's port re-publishes
the stack on the new port:

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

## 7. Backup

Everything worth keeping lives in three named volumes: the database
(`mysql-data`), logs and cache pools (`php-var`), and the JWT signing keys
(`jwt-keys`). A database dump before major updates:

```bash
docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod \
  exec mysql sh -c 'exec mysqldump -ufeedreader -p"$MYSQL_PASSWORD" feedreader' > backup.sql
```

Losing `jwt-keys` is not fatal — new keys are generated on the next start —
but it signs every user out.

## 8. Troubleshooting

- **Compose refuses to start and names a variable** — that value is empty in
  `.env.prod`. The comments in `.env.prod.example` explain each one.
- **Every request answers 500** — the runtime guard refuses to serve while a
  committed placeholder is in use (`ALTCHA_HMAC_KEY`, `MAILER_DSN`). The log
  names the variable:
  `docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod exec php tail -n 50 var/log/prod.log`
- **Mail says sent but never arrives** — run the `mailer:test` check above;
  then check the spam folder, then the transport's own logs. With the
  host-MTA DSN, check the host's mail queue (`mailq`).
- **Port 80/443 already taken** — set `WEB_HTTP_PORT` / `WEB_TLS_PORT` in
  `.env.prod` and re-run `./scripts/prod-start.sh`.
