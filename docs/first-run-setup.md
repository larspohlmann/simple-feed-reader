# First-run setup: creating the first administrator

A fresh install has no administrator, so no registered user can be approved and
the instance shows a one-time setup screen instead of login. Create the first
admin one of two ways. Both refuse to create a second bootstrap admin once one
exists.

**Installed with the Docker production stack?** The setup screen is already
prepared for you: `prod-start.sh` printed the secret in its summary. Go
straight to [Option 2](#option-2--the-web-setup-screen).

## Why it is gated

The first-admin path is the one moment an instance can be hijacked: whoever
creates that account owns the instance. So the path requires something only you,
the operator, hold — shell access, or a secret you set in the environment. The
first person to *register* is never promoted to admin.

## Option 1 — you have shell / `docker exec` access (recommended)

```bash
docker compose exec php bin/console app:admin:create you@example.com
```

On the production stack the same command needs the prod project's flags:

```bash
docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod \
  exec -u www-data php bin/console app:admin:create you@example.com
```

Enter a password (at least 12 characters) at the hidden prompt. The account is
created active, with the admin role, and can immediately reach `/api/admin`.
Re-running refuses once an admin exists; `--force` overrides for recovery.

Leave `ADMIN_SETUP_SECRET` unset in this case — the web setup endpoint then does
not exist at all. (Exception: the Docker production stack's `prod-start.sh`
sets one automatically so Option 2 works out of the box; the endpoint still
self-disables the moment the first admin exists, whichever way it was created.)

## Option 2 — the web setup screen

**On the Docker production stack this is automatic:** `scripts/prod-start.sh`
generates an `ADMIN_SETUP_SECRET` when the value is empty and prints it in its
summary while no administrator exists — see
[docker-production.md](docker-production.md) §3. The manual steps below are
for hosts where you configure the environment yourself:

1. Generate a high-entropy secret:

   ```bash
   openssl rand -hex 32
   ```

2. Set it as the `ADMIN_SETUP_SECRET` environment variable in your host's
   dashboard (the same place you set `DATABASE_URL`), and redeploy.
3. Open the app. The setup screen asks for an email, a password, and the secret.
4. Submit. You are created as the administrator and logged in.
5. Remove `ADMIN_SETUP_SECRET` from the environment. (The endpoint self-disables
   once an admin exists regardless, but removing the secret is tidy.)

The endpoint returns 404 whenever the secret is unset or an admin already
exists, and it is rate-limited (5 attempts per 15 minutes per IP).

## The registration gates, and running without mail

Once an administrator exists, two independent toggles decide what a new
sign-up needs before it can use the app. Both default to on. An
administrator reads and sets them with the admin settings endpoint,
`GET`/`PUT /api/admin/settings` (`ROLE_ADMIN`):

- **Require email confirmation** — a new email/password account must open
  the link in a confirmation mail before it can sign in.
- **Require admin approval** — every new account, however it was created
  (email/password or OAuth), sits as `pending_approval` until an
  administrator approves it — the same queue the bootstrap admin above
  bypasses.

Some instances are set up to send no mail at all. The installer's mail
question offers **"4) No mail"** for exactly that (see
[docker-production.md](docker-production.md) §5, "Running without mail").
On a mailless instance, "require email confirmation" is forced off no
matter how it is set — nothing can deliver the confirmation link — while
"require admin approval" is untouched, since an administrator can still
approve an account by hand. The settings endpoint's response includes
`mailEnabled` so a caller can tell the two apart: the stored toggle versus
the effective, mail-forced value.

## The passkey relying party

The same `GET`/`PUT /api/admin/settings` endpoint also holds the WebAuthn
relying party. It defaults to the host of the public base URL; an
administrator can override it in Settings → Admin, and that page explains
the value in full. Changing it invalidates every passkey already enrolled.

**Passkey sign-in is off by default.** A fresh install ships with it
invisible — no "Sign in with a passkey" button, no Settings → Account
passkeys group, no first-login enrolment offer — until an administrator
switches it on from **Settings → Admin**, the "Allow passkey sign-in" toggle
beside the relying-party fields above. This is deliberate: "activated" is
meant to mean activated, not "on until someone remembers to turn it off."
Turning it off again — for example while the relying party above is
misconfigured — refuses every passkey endpoint server-side, not just the
frontend buttons; an already-enrolled user can still remove their own
passkeys while it is off, but cannot sign in, register a new one, or list
the ones they already have.
