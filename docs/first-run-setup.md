# First-run setup: creating the first administrator

A fresh install has no administrator, so no registered user can be approved and
the instance shows a one-time setup screen instead of login. Create the first
admin one of two ways. Both refuse to create a second bootstrap admin once one
exists.

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
not exist at all.

## Option 2 — no shell (cheap Docker hosts)

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
