# Personal Strato deployment

This directory is the maintainer's own deployment of this app to STRATO shared hosting.
**It is not the supported way to run the project** — the Docker setup in the repository root
is. Nothing here is needed to develop, test, or run the app, and nothing outside this
directory (plus one workflow file) knows it exists. Read on only if you are deploying to
that particular host.

The app is served at <https://lars-pohlmann.de/reader>. It is mounted at a subpath rather
than on a subdomain because STRATO's included certificate covers only the apex domain and
`www`; the apex is already certified, so `/reader` gets HTTPS for nothing. A wildcard
certificate is the only alternative and it costs money.

## How it fits together

```
~/larspohlmann/reader  ->  ~/simplefeedreader/current/public
~/simplefeedreader/
  releases/<name>/           one directory per deploy, five kept
  current -> releases/<name> flipped last, atomically
  shared/
    .env.local               secrets; symlinked into each release
    config/jwt/              signing keypair; symlinked into each release
    var/log/                 outlives a release
    var/cache-pools/         rate limiter, ALTCHA replay, OAuth state, login codes
```

Symfony derives its base path from `SCRIPT_NAME`, so no route carries a `/reader` prefix and
none needs to. Angular gets its `/reader/` base href from the `strato` build configuration.
`public/.htaccess` sends `/api` and `/maintenance` to `index.php`, serves anything that
exists on disk as it is, and falls back to `index.html` for everything else so client-side
routes survive a reload.

Everything the app must not lose between deploys lives in `shared/` and is symlinked into
the release during activation. That is the whole trick: the release directory is disposable,
`shared/` is not.

## One-time setup

Do these in order; activation fails loudly if the last three are missing.

1. **MySQL database** — create it in the Strato panel. The DSN goes in `shared/.env.local`.
2. **Mailbox** — create `noreply@lars-pohlmann.de`. Its SMTP credentials go in the same
   file, and the address must also be `MAIL_FROM`: Strato will not relay mail claiming a
   sender you did not authenticate as.
3. ~~**PHP version**~~ — nothing to do. The vhost was measured serving **PHP 8.4.22**
   (cgi-fcgi) on 2026-07-25, which is what reader mode needs (readability.php v4).
4. **Google OAuth** — register the redirect URI, exactly:
   `https://lars-pohlmann.de/reader/api/auth/oauth/google/callback`
5. **Subdomain** — point `reader.lars-pohlmann.de` at `https://lars-pohlmann.de/reader`.
   That redirect travels over plain HTTP, because the subdomain has no certificate. It is a
   convenience for old links, not an entry point anyone should be given.
6. **JWT keys** — generate them locally and upload **both** files. Activation refuses to run
   unless `shared/config/jwt/private.pem` *and* `shared/config/jwt/public.pem` are present,
   because a release that silently came up without a signing key would fail every login at
   runtime instead of at deploy time.

   ```bash
   ssh strato-feedreader 'mkdir -p ~/simplefeedreader/shared/config/jwt'
   openssl genpkey -out private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096
   openssl pkey -in private.pem -out public.pem -pubout
   scp private.pem public.pem strato-feedreader:~/simplefeedreader/shared/config/jwt/
   ssh strato-feedreader 'chmod 600 ~/simplefeedreader/shared/config/jwt/private.pem'
   ```

   The passphrase you typed goes in `JWT_PASSPHRASE`. Keep the keypair: it is shared across
   releases on purpose, and replacing it logs every user out.
7. **Environment** — copy `.env.local.example` to `shared/.env.local`, fill it in, and
   `chmod 600` it. It holds the database password. Read the comments in that file rather
   than skimming the variable names; several of the committed defaults are functional, which
   is exactly what makes forgetting them expensive.
8. **Mount** — link the app into the portfolio docroot:

   ```bash
   ssh strato-feedreader 'ln -sfn ~/simplefeedreader/current/public ~/larspohlmann/reader'
   ```

### What activation checks, and why only these

`activate-release.sh` aborts before touching anything if `shared/.env.local` does not set
`APP_ENV=prod`, or does not point `CACHE_DIRECTORY` at an absolute path. Both are checks for
"you forgot to override a default", not judgements about your values, and both defaults fail
in the same quiet way — the deploy succeeds and the site looks healthy.

Missing `APP_ENV=prod`, the cache is warmed for `dev` and the site goes live in debug mode,
serving stack traces — with the database credentials in them — to the public internet.

Left kernel-relative, `CACHE_DIRECTORY` resolves inside the release's own `var/cache`, which
the same script wipes on the next deploy. That silently resets the rate-limit counters and
the record of spent ALTCHA solutions on every single deploy, re-opening the replay window
each time. Absolute is what rules the default out; pointing it into `shared/` is your job.

Two other placeholders fail at runtime rather than at deploy time, because secrets may
legitimately be absent while the cache is warmed: if `ALTCHA_HMAC_KEY` still holds the value
committed to this public repository, or `MAILER_DSN` is still `null://null`,
`InsecureProductionConfigGuard` refuses **every** request with a 500 and logs which variable
to set. That is deliberate — a site with a void CAPTCHA and a black-hole mailer is not
degraded, it is quietly failing at the things it exists for.

## Deploying

**`develop` is continuously deployed.** Every push or merge to `develop` runs CI, and if CI
passes the deploy workflow builds both halves on the runner, uploads the release, warms the
cache, migrates, and flips `current`. Migrations run **before** the flip, so a failed
migration leaves the previous release serving traffic. Merge to `develop` and it ships.

Two caveats:

- `workflow_run` only fires when the deploy workflow is on the **default branch** (`main`).
  Until `develop` has been merged to `main` once, automatic deploys do not happen.
- To deploy on demand, run the **Deploy (Strato)** workflow from the Actions tab.

You can also assemble a release by hand — `./deploy/strato/build-release.sh /tmp/release` —
which is mostly useful for inspecting what would be shipped. The script strips dev
dependencies from `backend/vendor` to build, and restores them on the way out whatever
happens, so there is nothing to reinstall afterwards.

## Running a console command on the server

The host's PHP is `php84` and its SAPI is **cgi-fcgi**, not cli. That changes how a command
line has to be spelled, and the failure is not obvious:

```bash
ssh strato-feedreader \
  'php84 -q -f ~/simplefeedreader/current/bin/console -- doctrine:migrations:status'
```

`-q` suppresses the HTTP headers the SAPI would otherwise print ahead of the output. **The
`--` is mandatory.** The SAPI keeps parsing its own options past the script name, so the
first dash-prefixed argument aborts with

```
Error in argument 4, char 2: no argument for option -
```

and exit status 1, before PHP starts. Non-dash arguments happen to work without the
separator, which is how an earlier probe of this host missed it — do not take a command that
worked once as evidence that the separator is optional. Always write it. The path must be
absolute, too: the SAPI does `chdir()` into the script's directory, but only after it has
found the file, and an SSH command starts in `$HOME`.

`php -r` does not work at all here.

## Rolling back

Pick a release, then flip `current` the same way the deploy script does:

```bash
ssh strato-feedreader 'ls -1t ~/simplefeedreader/releases'
ssh strato-feedreader 'cd ~/simplefeedreader \
  && ln -sfn "$PWD/releases/<previous>" current.tmp \
  && mv -Tf current.tmp current'
```

Do **not** shorten this to `ln -sfn <target> current`. Over an existing symlink, `ln -sfn`
is `unlink()` then `symlink()`, and for the instant in between `current` does not exist —
in-flight requests resolve a dangling path and Apache answers 404 or 500. Creating the link
beside it and renaming it over the old one is a single `rename(2)`, which is atomic. `-T`
also makes the command fail loudly instead of nesting a link inside `current` should
`current` ever be a real directory.

No rebuild is needed: the old release is intact on disk, still carrying the symlinks its own
activation created. Five releases are kept, so anything older than that is gone.

What a rollback does **not** do, because `activate-release.sh` is not re-run:

- **No migration is reversed.** Down-migrations are not part of this setup. If the release
  you are backing out of changed the schema, the schema stays changed, and the older code
  now runs against a newer database. Additive changes are usually survivable; a dropped or
  renamed column is not, and in that case the fix is forward, not back.
- **No cache is warmed.** The old release's `var/cache` is whatever it was when that release
  was deployed, which is correct and complete — but if you rolled back because a deploy died
  *during* warmup, the half-written cache of the *failed* release is still sitting in its own
  directory and will be rebuilt from scratch when it is next activated.

## Verifying a deploy

1. <https://lars-pohlmann.de/reader> loads over a valid certificate.
2. `https://lars-pohlmann.de/reader/api/health` responds.
3. A client-side route survives a browser reload (proves the SPA fallback).
4. Register → verification mail arrives → verify → approve. This exercises the real SMTP
   transport and the real ALTCHA key at once; if either is still a placeholder you will not
   get this far, you will get a 500 on every page.
5. Google sign-in completes and lands back under `/reader`.
6. Subscribe to a feed, refresh, open an article, switch to reader mode — this is what
   proves PHP 8.4 on the vhost.
7. <https://lars-pohlmann.de/> and `/de/` still work. The mount must not disturb the
   portfolio it lives inside.
8. Deploy again: logins survive (shared JWT keys) and rate-limit state survives (shared
   cache pools). This is the one check that catches a `CACHE_DIRECTORY` that quietly points
   into the release.

## Notes

- There is **no scheduled refresh**. Feeds update when someone presses refresh in the UI.
  `POST /maintenance/refresh` exists for an external pinger, but `MAINTENANCE_TOKEN` is empty
  and an empty token is fail-closed — the endpoint refuses everything until one is set.
- The server has no composer, no node, and no crontab. Everything is built on the runner.
- **`api` and `maintenance` are reserved top-level names.** The `.htaccess` routes them to
  Symfony, so a static asset or directory with either name would be swallowed before the SPA
  ever saw it. Nothing in the current build produces one.

## Host capabilities — measured, not assumed (2026-07-25)

Every assumption this deployment rests on was probed on the live host by mounting a throwaway
directory under the portfolio docroot and driving it over HTTPS. The probe was removed
afterwards and the portfolio verified intact. **All of it passed**, which retires the risks
earlier review rounds had flagged as undiagnosable:

| Assumption | Result |
| --- | --- |
| Apache version | 2.4.68 (Unix) |
| `.htaccess` honoured at all | yes — `DirectoryIndex` took effect |
| `AllowOverride` permits `Options` | **yes** — no 500; this was the biggest flagged risk |
| `mod_rewrite` | yes — all four request shapes routed correctly |
| `mod_headers` | yes — `Header set` reached the client |
| `mod_authz_core` | yes — dotfile denied with 403 |
| Symlinked directory served over the web | **yes** — 200 through a symlink in the docroot |
| Web-context PHP | **8.4.22**, cgi-fcgi — already correct, no panel change needed |
| Required extensions | all present (ctype, dom, iconv, libxml, filter, mbstring, openssl, sodium, xml, pdo_mysql, curl, intl, tokenizer, session, json) |
| `memory_limit` / `max_execution_time` | 512M / 240s |
| opcache / `allow_url_fopen` / `disable_functions` | on / on / none |
| `date.timezone` | UTC — matches how the app persists datetimes |

The decisive detail for the subpath: a request to `/_probe/d/api/health` arrived with
`SCRIPT_NAME=/_probe/d/index.php` and `REQUEST_URI=/_probe/d/api/health` — exactly the pair
Symfony uses to derive a base URL of `/_probe/d` and a path info of `/api/health`. The mount
mechanism is confirmed, not inferred.

### The database

It exists: `dbs15919276` on **`database-5020972012.webspace-host.com`**, port 3306. Three
things were established about it, and each one shapes the deploy:

- **Migrations over SSH work.** TCP 3306 is open from the shell host, and a PDO connection
  with a deliberately wrong password came back `1045 Access denied for user
  'dbs15919276'@'swh-live-shell002.swh.1u1.it'` — the handshake completed and only the
  credentials were refused. So running `doctrine:migrations:migrate` from
  `activate-release.sh` is sound; no web-triggered migration fallback is needed.
- **Never use the host's `mysql` CLI against this database.** It is a MySQL 5.6 client
  (`/opt/RZmysql56/`) and fails with `ERROR 2059 … caching_sha2_password cannot be loaded`
  against what is clearly a MySQL 8 server. PHP's mysqlnd negotiates it fine. This rules out
  shell-based dumps or imports through that client — relevant if a backup step is ever added.
- **Grants are host-scoped.** MySQL returns the same 1045 for "wrong password" and "this host
  may not connect", so whether the *shell* host is granted access is only proven on the first
  real deploy. If migrations fail with 1045 despite correct credentials, that is the cause.

**What the probe did NOT cover**, and still needs watching on the first real deploy:

- The probe ran a two-line PHP file, not Symfony. Booting the real kernel under cgi-fcgi —
  and running the console for migrations — is exercised for the first time on deploy.
- `fastcgi_finish_request()` does not exist under cgi-fcgi, so the deferred-mailer timing
  guarantee is weaker here than the code's comments assume.
- MySQL 8 commits DDL implicitly, so a migration killed by the 240s `max_execution_time`
  leaves a half-changed schema with no row in `doctrine_migration_versions`. A blind retry
  then fails on something like "Duplicate column name". Check
  `doctrine:migrations:status` before retrying a failed deploy.
