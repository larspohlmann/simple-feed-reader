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
  releases/<name>/           one directory per deploy; the workflow prunes old ones
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

Do these in order. Activation checks two of them: it refuses to run without the JWT keypair
(7) or `shared/.env.local` (8), and it reads that file's contents. It checks nothing about
the mount (10), because `activate-release.sh` never looks outside the deploy root and has no
business doing so — which makes a missing or misaimed mount precisely the silent failure you
might hope a script would catch. Activation prints "Active release is now …" and exits 0
while the URL 404s. Verification step 1 is what catches that.

1. **Deploy root skeleton** — create the two directories every later step writes into:

   ```bash
   ssh strato-feedreader 'mkdir -p ~/simplefeedreader/releases ~/simplefeedreader/shared'
   ```

   Nothing else creates `releases/`. Activation creates `shared/var/*` itself, and the JWT
   step below creates `shared/config/jwt`, but the upload lands *before* activation runs and
   `rsync` creates only the final path component. On a bare deploy root the deploy therefore
   dies at the upload with `mkdir failed: No such file or directory` — before anything on the
   server has changed, but with nothing in the message to say which directory it meant.
2. **MySQL database** — create it in the Strato panel. The DSN goes in `shared/.env.local`.
3. **Mailbox** — create `noreply@lars-pohlmann.de`. Its SMTP credentials go in the same
   file, and the address must also be `MAIL_FROM`: Strato will not relay mail claiming a
   sender you did not authenticate as.
4. ~~**PHP version**~~ — nothing to do. The vhost was measured serving **PHP 8.4.22**
   (cgi-fcgi) on 2026-07-25, which is what reader mode needs (readability.php v4). The shell
   side runs the same 8.4.22 build through a real CLI binary — see *Running a console command
   on the server* below.
5. **Google OAuth** — register the redirect URI, exactly:
   `https://lars-pohlmann.de/reader/api/auth/oauth/google/callback`
6. **Subdomain** — `reader.lars-pohlmann.de` already exists and already points at
   `~/simplefeedreader/current/public`, so this is a *re*-point, not fresh setup: aim it at
   `https://lars-pohlmann.de/reader` instead. That redirect travels over plain HTTP, because
   the subdomain has no certificate. It is a convenience for old links, not an entry point
   anyone should be given.
7. **JWT keys** — generate them locally and upload **both** files. Activation refuses to run
   unless `shared/config/jwt/private.pem` *and* `shared/config/jwt/public.pem` are present,
   because a release that silently came up without a signing key would fail every login at
   runtime instead of at deploy time.

   Generate them in a scratch directory, **not in the repository checkout** — which is
   where you are most likely standing while reading this. `backend/.gitignore` ignores
   `/config/jwt/*.pem` and nothing else, so a `private.pem` sitting at the repo root is not
   ignored, and one `git add -A` publishes a 4096-bit signing key to a public repository.

   ```bash
   ssh strato-feedreader 'mkdir -p ~/simplefeedreader/shared/config/jwt'
   cd "$(mktemp -d)"
   openssl genpkey -out private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096
   openssl pkey -in private.pem -out public.pem -pubout
   chmod 600 private.pem
   scp private.pem public.pem strato-feedreader:~/simplefeedreader/shared/config/jwt/
   ssh strato-feedreader 'chmod 600 ~/simplefeedreader/shared/config/jwt/private.pem'
   rm -f private.pem public.pem
   ```

   The `cd` and the `rm` are the substance of that block, not tidiness: the scratch
   directory keeps the key out of any tree `git` can see, and the `chmod 600` guards it
   against your umask for the minute it exists. The server copy is the one that matters.

   The passphrase you typed goes in `JWT_PASSPHRASE`. Keep the keypair: it is shared across
   releases on purpose, and replacing it logs every user out. "Keep" means the copy on the
   server plus, if you want a backup, one in your password manager alongside the passphrase
   — the same place, because the key without the passphrase is useless and either alone is
   not a recovery. Do not keep a backup loose on disk; the lines above delete the local
   copies deliberately, and re-uploading the server's copy is what a restore looks like.
8. **Environment** — copy `.env.local.example` to `shared/.env.local`, fill it in, and
   `chmod 600` it. It holds the database password. Read the comments in that file rather
   than skimming the variable names; several of the committed defaults are functional, which
   is exactly what makes forgetting them expensive.
9. **Remove the placeholder `current`** — `~/simplefeedreader/current` exists on the host
   as a **real directory**, holding a placeholder `public/index.html` from before this
   deployment was built. It has to be gone before the first deploy:

   ```bash
   ssh strato-feedreader 'rm -rf ~/simplefeedreader/current'
   ```

   The flip at the end of `activate-release.sh` is `mv -Tf current.tmp current`, and `-T`
   refuses, by design, to replace a real directory with a symlink. That refusal is the
   script's **last** line, so it fires after the shared state has been linked, the cache
   warmed and the migrations run: the failure would leave you with a migrated production
   schema, no deploy, and the placeholder still serving the URL.

10. **Mount** — link the app into the portfolio docroot:

    ```bash
    ssh strato-feedreader 'ln -sfn ~/simplefeedreader/current/public ~/larspohlmann/reader'
    ```

    The link dangles until the first deploy creates `current`. That is expected.

11. **First admin** — do this **after** the first successful deploy; it needs a migrated
    database and a `current` that resolves.

    Nothing in this codebase grants `ROLE_ADMIN` in production. The one command that grants
    it, `app:e2e:seed-admin`, refuses to run under `APP_ENV=prod`, and no migration seeds an
    account. So a fresh production database has no administrator — and without one, every
    registration stops at `pending_approval` forever, because `UserChecker` refuses to
    authenticate anything that is not `active` and only `ROLE_ADMIN` can approve. Including
    your own account. This step is the way out of that, and it happens once, ever.

    Register your own account through the UI first, so the row exists and carries a real
    password hash — there is no other way to get one in. The verification mail does not have
    to have arrived: the statement below makes the account active outright.

    ```bash
    ssh strato-feedreader "/opt/RZphp84/bin/php-cli ~/simplefeedreader/current/bin/console dbal:run-sql \"UPDATE app_user SET roles = JSON_ARRAY('ROLE_ADMIN'), status = 'active', approved_at = UTC_TIMESTAMP() WHERE email = 'you@example.com'\""
    ```

    It reports one row affected. Read it back before believing it:

    ```bash
    ssh strato-feedreader "/opt/RZphp84/bin/php-cli ~/simplefeedreader/current/bin/console dbal:run-sql \"SELECT id, email, roles, status, approved_at FROM app_user\""
    ```

    Each part of that statement is load-bearing:

    - `/opt/RZphp84/bin/php-cli` is the host's real PHP CLI. It is not on `PATH`, which is
      why the absolute path is spelled out; *Running a console command on the server* below
      explains why the `php84` on `PATH` is the wrong binary for this.
    - `dbal:run-sql` is doctrine-bundle's own command. It is registered in **every**
      environment, not just dev, and it reaches the database through PHP's mysqlnd. That is
      the point: the host's `mysql` CLI is a 5.6 client and cannot authenticate to this
      MySQL 8 server at all (see *The database* below), so no shell client will do this job.
    - `roles` is a JSON column. `JSON_ARRAY('ROLE_ADMIN')` yields `["ROLE_ADMIN"]` without
      putting a double quote anywhere on the command line, which is the only reason the
      statement survives two levels of shell quoting intact. Do not add `ROLE_USER`;
      `User::getRoles()` appends it at runtime.
    - `status` is a string-backed enum and `active` is the stored value — see
      `App\Enum\UserStatus`, not the PHP case name.
    - `UTC_TIMESTAMP()`, not `NOW()`: every datetime in this schema is a naive UTC wall
      clock, and `NOW()` would write whatever the MySQL session's timezone happens to be.

    Column names come from the `app_user` DDL in `Version20260721153011`, not from the
    entity's property names — they differ.

    From here on everyone registers normally and you approve them in the admin UI.

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

**None of that works until the workflow file is on `main`.** GitHub only reads workflow
definitions from the **default branch** for the triggers this workflow uses — `workflow_run`
*and* `workflow_dispatch` alike. So while the deploy workflow exists only on `develop` it
does not appear in the Actions tab at all, and `gh workflow run "Deploy (Strato)"` fails with
*could not find any workflows named…*. There is no "run it manually in the meantime": the
first deploy requires merging to `main` first, or deploying by hand.

### Deploying by hand

This is the genuine interim path, and it is also what you fall back to if the runner is
unavailable. It is the same sequence the workflow performs, with the same release-name
convention (a sortable UTC timestamp plus the short SHA being deployed, e.g.
`20260725143012-a1b2c3d`):

```bash
name="$(date -u +%Y%m%d%H%M%S)-$(git rev-parse --short HEAD)"

./deploy/strato/build-release.sh /tmp/sfr-release
cp deploy/strato/activate-release.sh /tmp/sfr-release/activate-release.sh
rsync -az "/tmp/sfr-release/" "strato-feedreader:simplefeedreader/releases/${name}/"
ssh strato-feedreader "bash ~/simplefeedreader/releases/${name}/activate-release.sh ~/simplefeedreader '${name}'"
```

The `cp` is not optional: the workflow ships the activation script *inside* the release, so
that a release on disk stays activatable later by exactly the script it was built against.

The rsync destination is written **relative**, without a `~`. A remote path with no leading
slash is resolved against the remote user's home directory by rsync itself, whereas a `~`
depends on whether this rsync version hands the path to a remote shell at all — since 3.2.4
it usually does not. The `~` in the `ssh` line is fine: that one really is a shell command,
and the remote shell expands the tilde before `bash` ever runs — which matters, because an
SSH command starts in `$HOME` and `current` ends up storing a path built from that argument.

Building alone — `./deploy/strato/build-release.sh /tmp/release` — is also useful just for
inspecting what would be shipped. The script strips dev dependencies from `backend/vendor` to
build, and restores them on the way out whatever happens, so there is nothing to reinstall
afterwards — unless the restore itself fails, which it tells you about, printing the
`composer install` to run by hand.

A hand-uploaded release is an ordinary release: the next deploy prunes it by the same rule as
any other.

## Running a console command on the server

**The host has two PHP binaries, and the one on `PATH` is the wrong one.** They are the same
build — PHP 8.4.22, built 8 June 2026 — and differ only in SAPI:

| | `php84` (on `PATH`) | `/opt/RZphp84/bin/php-cli` |
| --- | --- | --- |
| SAPI | cgi-fcgi | cli |
| `max_execution_time` | **240s** | **0 — no ceiling** |
| arguments | `-q -f <script> -- <args>`, the `--` mandatory | ordinary; dashes pass straight through |
| working directory | `chdir()`s into the script's directory | unchanged |
| `php -r` | unavailable | works |
| `memory_limit`, extensions, `disable_functions`, `date.timezone` | 512M, all present, none, UTC | identical |

`php84` is a symlink to the cgi-fcgi binary. It is what a shell finds, which is why every
earlier note here was written around its quirks, but it is not the binary you want: its 240
second execution ceiling is enough to kill a migration on a table with real data, and MySQL
commits DDL implicitly, so being killed halfway is not recoverable by a retry.

So use the real CLI, by its absolute path — it is simply not on `PATH`:

```bash
ssh strato-feedreader \
  '/opt/RZphp84/bin/php-cli ~/simplefeedreader/current/bin/console doctrine:migrations:status'
```

That is exactly what `activate-release.sh` does, deliberately: a command you run by hand and a
command the deploy ran should be the same command. The path to `bin/console` must still be
absolute — the CLI does **not** `chdir()`, it stays where it was invoked, and an SSH command
starts in `$HOME`. The `~` above qualifies; a bare `bin/console` does not.

The path shape is a Strato convention rather than a lucky find: `php-cli` exists identically
under `/opt/RZphp82`, `RZphp83`, `RZphp84` and `RZphp85`, and the ini is
`/opt/RZphp84/etc/php.ini`. `activate-release.sh` checks the binary is there before doing
anything and tells you this if it is not.

**If Strato ever reorganises `/opt`**, find the new path with
`ssh strato-feedreader 'ls -d /opt/RZphp*/bin/php-cli'` — and the cgi-fcgi binary still works
as a fallback, spelled the long way:

```bash
ssh strato-feedreader \
  'php84 -q -f ~/simplefeedreader/current/bin/console -- doctrine:migrations:status'
```

In that form `-q` suppresses the HTTP headers the SAPI prints ahead of the output, and **the
`--` is mandatory**: the SAPI keeps parsing its own options past the script name, so the first
dash-prefixed argument aborts with `Error in argument 4, char 2: no argument for option -`
and exit status 1, before PHP starts. Non-dash arguments happen to work without the
separator, which is how an earlier probe of this host missed it — do not take a command that
worked once as evidence that the separator is optional.

The `-d register_argc_argv=1` that used to be pinned on every one of these command lines is
gone. It guarded against a silent failure that only exists under cgi-fcgi: Symfony's
`ArgvInput` reads `$_SERVER['argv']`, so with the setting off every command would degrade
into `bin/console list` and exit 0 — a deploy reporting success having migrated nothing. The
CLI SAPI populates `argv` unconditionally, ini or no ini, so there is nothing left to pin.

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
activation created. Pruning is the deploy workflow's job, not the activation script's, and it
keeps the five newest releases **plus the live one** — the prune resolves `current` first and
refuses to delete whatever it points at, even when that release has aged past the fifth
position, which is exactly what a rollback makes happen. Everything else that old is already
gone, and a hand-uploaded release is subject to the same rule on the next deploy.

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
4. Register → verification mail arrives → verify. This exercises the real SMTP transport
   and the real ALTCHA key at once; if either is still a placeholder you will not get this
   far, you will get a 500 on every page. The account then stops at `pending_approval`, and
   that is correct, not a fault: approval needs an admin, and on a fresh database the first
   one is made by hand in one-time setup step 10. Approving *from the UI* is only a check
   once that step has been done and you are signed in as that admin.
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
| Shell-context PHP | **8.4.22 cli** at `/opt/RZphp84/bin/php-cli` — same build as the vhost's, not on `PATH`; 512M, `max_execution_time` **0**, all required extensions, no `disable_functions`, UTC, exit codes propagate |

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

- The probe ran a two-line PHP file, not Symfony. Booting the real kernel under cgi-fcgi on
  the vhost — and running the console under the CLI for migrations — is exercised for the
  first time on deploy.
- `fastcgi_finish_request()` does not exist under cgi-fcgi, so the deferred-mailer timing
  guarantee is weaker here than the code's comments assume. This one is about the *web*
  SAPI and is unaffected by which binary the deploy uses.
- MySQL 8 commits DDL implicitly, so a migration that dies partway through leaves a
  half-changed schema with no row in `doctrine_migration_versions`, and a blind retry then
  fails on something like "Duplicate column name". Check `doctrine:migrations:status` before
  retrying a failed deploy. A **timeout** is no longer one of the ways this happens: the CLI
  has no execution ceiling, and the 240s limit in the table above belongs to the cgi-fcgi
  SAPI, which the deploy does not use.
